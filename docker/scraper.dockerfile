# Extend the official SeleniumBase scraper image with Google Chrome support.
# The official image uses snap-based Chromium which doesn't work in Docker
# on ARM64 (aarch64). This Dockerfile installs Google Chrome natively.
FROM jez500/seleniumbase-scrapper:latest

USER root

# Install Google Chrome (works on both amd64 and arm64)
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget \
    gnupg2 \
    && wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg \
    && echo "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends google-chrome-stable \
    && rm -rf /var/lib/apt/lists/*

# Symlink so the API's chromium detection path triggers non-UC mode
# (the API sets uc=False when /usr/bin/chromium-browser exists, which avoids
# the x86-only uc_driver binary that fails on ARM64)
RUN ln -sf /usr/bin/google-chrome-stable /usr/bin/chromium-browser
