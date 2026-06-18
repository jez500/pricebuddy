<x-filament-panels::page>
    <div class="w-full max-w-xl">
        <x-filament::section>
            <x-slot name="heading">
                API key &ldquo;{{ $keyName }}&rdquo; created
            </x-slot>

            <div class="rounded-lg bg-warning-50 p-4 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                <strong>Copy this token now.</strong> For security it is only shown once and cannot be retrieved again.
            </div>

            <div
                x-data="{
                    token: @js($plainTextToken),
                    copied: false,
                    done() { this.copied = true; setTimeout(() => this.copied = false, 2000); },
                    copy() {
                        if (window.navigator.clipboard && window.isSecureContext) {
                            window.navigator.clipboard.writeText(this.token)
                                .then(() => this.done())
                                .catch(() => this.fallbackCopy());
                        } else {
                            this.fallbackCopy();
                        }
                    },
                    fallbackCopy() {
                        const el = this.$refs.token;
                        el.removeAttribute('readonly');
                        el.select();
                        el.setSelectionRange(0, el.value.length);
                        document.execCommand('copy');
                        el.setAttribute('readonly', 'readonly');
                        window.getSelection().removeAllRanges();
                        this.done();
                    }
                }"
                class="mt-4 flex items-center gap-2"
            >
                <input
                    type="text"
                    readonly
                    x-ref="token"
                    :value="token"
                    class="fi-input block w-full rounded-lg border-none bg-white py-2 px-3 font-mono text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                />
                <x-filament::button
                    x-on:click="copy()"
                    icon="heroicon-m-clipboard-document"
                >
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </x-filament::button>
            </div>

            <div class="mt-6">
                <x-filament::button
                    tag="a"
                    color="gray"
                    :href="\App\Filament\Resources\ApiKeyResource::getUrl('index')"
                >
                    Done
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
