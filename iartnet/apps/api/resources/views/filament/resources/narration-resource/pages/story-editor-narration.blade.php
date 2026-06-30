<x-filament-panels::page>
    <div
        x-data="{
            dirty: false,
            init() {
                const origin = window.location.origin;
                const initPayload = @js($this->getStoryInitPayload());
                const iframe = this.$refs.storyEditorFrame;

                const sendInit = () => {
                    if (!iframe?.contentWindow) {
                        return;
                    }
                    iframe.contentWindow.postMessage({
                        type: 'STORY_EDITOR_INIT',
                        payload: initPayload,
                    }, origin);
                };

                iframe?.addEventListener('load', sendInit);

                if (iframe?.contentDocument?.readyState === 'complete') {
                    sendInit();
                }

                window.addEventListener('message', (event) => {
                    if (event.origin !== origin) {
                        return;
                    }

                    const data = event.data;
                    if (!data || typeof data !== 'object') {
                        return;
                    }

                    if (data.type === 'STORY_EDITOR_READY') {
                        sendInit();
                    }

                    if (data.type === 'STORY_EDITOR_SAVE' && data.payload?.ext_json) {
                        $wire.saveStoryFromEditor(data.payload.ext_json);
                        this.dirty = false;
                    }

                    if (data.type === 'STORY_EDITOR_DIRTY') {
                        this.dirty = Boolean(data.dirty);
                    }

                    if (data.type === 'STORY_EDITOR_CANCEL') {
                        window.location.href = @js($this->getEditUrl());
                    }
                });
            },
        }"
        class="flex flex-col gap-2"
    >
        <div
            x-show="dirty"
            x-cloak
            class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            Modifiche non salvate nell&apos;editor. Usa &quot;Salva nella narrazione&quot; prima di uscire.
        </div>

        <iframe
            x-ref="storyEditorFrame"
            src="{{ asset('stories-editor/index.html') }}?embed=1"
            title="Stories Editor"
            class="w-full rounded-lg border border-gray-200 bg-white"
            style="min-height: calc(100vh - 12rem);"
        ></iframe>
    </div>
</x-filament-panels::page>
