# Extend the official SeleniumBase scraper image with Google Chrome support.
# The official image uses snap-based Chromium which doesn't work in Docker
# on ARM64 (aarch64). This Dockerfile installs Google Chrome natively,
# downloads the correct ARM64 chromedriver, and patches Selenium's
# DriverFinder to fallback when SeleniumManager doesn't work on ARM64.
FROM jez500/seleniumbase-scrapper:latest

USER root

# Install Google Chrome (works on both amd64 and arm64)
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget \
    gnupg2 \
    unzip \
    && wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg \
    && echo "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends google-chrome-stable \
    && rm -rf /var/lib/apt/lists/*

# Download the correct chromedriver for the installed Chrome version and
# host architecture (arm64 or amd64).
RUN python3 -c "\
import json, subprocess, urllib.request, zipfile, os, struct, shutil; \
arch = 'linux-arm64' if struct.calcsize('P') * 8 == 64 and os.uname().machine in ('aarch64', 'arm64') else 'linux64'; \
chrome_ver = subprocess.check_output(['google-chrome-stable', '--version']).decode().strip().split()[-1]; \
major = chrome_ver.split('.')[0]; \
print(f'Chrome {chrome_ver}, arch={arch}'); \
data = json.loads(urllib.request.urlopen('https://googlechromelabs.github.io/chrome-for-testing/known-good-versions-with-downloads.json').read()); \
versions = [v for v in data['versions'] if v['version'].startswith(major + '.') and v.get('downloads', {}).get('chromedriver')]; \
url = next(d['url'] for v in reversed(versions) for d in v['downloads']['chromedriver'] if arch in d['url']); \
print(f'Downloading chromedriver: {url}'); \
urllib.request.urlretrieve(url, '/tmp/cd.zip'); \
z = zipfile.ZipFile('/tmp/cd.zip'); \
cd = [n for n in z.namelist() if n.endswith('/chromedriver')][0]; \
z.extract(cd, '/tmp'); \
sb_dir = '/usr/local/lib/python3.10/dist-packages/seleniumbase/drivers'; \
os.makedirs(sb_dir, exist_ok=True); \
shutil.move(f'/tmp/{cd}', f'{sb_dir}/chromedriver'); \
os.chmod(f'{sb_dir}/chromedriver', 0o755); \
shutil.copy2(f'{sb_dir}/chromedriver', '/usr/local/bin/chromedriver'); \
print(f'chromedriver: {subprocess.check_output([\"/usr/local/bin/chromedriver\", \"--version\"]).decode().strip()}')"

# Patch DriverFinder._binary_paths on disk to fall back to PATH chromedriver
# when SeleniumManager fails (SeleniumManager doesn't support ARM64/aarch64).
RUN python3 << 'PYEOF'
import pathlib

# Patch driver_finder.py - replace the broad except that wraps everything
# in NoSuchDriverException with a fallback to PATH lookup
df_path = pathlib.Path("/usr/local/lib/python3.10/dist-packages/selenium/webdriver/common/driver_finder.py")
src = df_path.read_text()

old = '''        except Exception as err:
            msg = f"Unable to obtain driver for {browser}"
            raise NoSuchDriverException(msg) from err
        return self._paths'''

new = '''        except Exception:
            import shutil
            cd = shutil.which("chromedriver")
            if cd:
                self._paths["driver_path"] = cd
            else:
                msg = f"Unable to obtain driver for {browser}"
                raise NoSuchDriverException(msg)
        return self._paths'''

if old in src:
    df_path.write_text(src.replace(old, new))
    print("Patched driver_finder.py - PATH fallback added")
else:
    print("ERROR: Could not find target in driver_finder.py")
    # Show context for debugging
    idx = src.find('except Exception')
    if idx >= 0:
        print(repr(src[max(0,idx-50):idx+200]))
PYEOF

# Symlink so the API's chromium detection path triggers non-UC mode
# (the API sets uc=False when /usr/bin/chromium-browser exists, which avoids
# the x86-only uc_driver binary that fails on ARM64)
RUN ln -sf /usr/bin/google-chrome-stable /usr/bin/chromium-browser
