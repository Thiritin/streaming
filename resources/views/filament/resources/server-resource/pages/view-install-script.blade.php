<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Server Information -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">Server Information</h3>
            <dl class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Server ID</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->id }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Type</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ ucfirst($record->type->value) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ ucfirst($record->status->value) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Hostname</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->hostname ?: 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">IP Address</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->ip ?: 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Shared Secret</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 font-mono">
                        <span class="blur-sm hover:blur-none transition-all cursor-pointer" title="Click to reveal">
                            {{ $record->shared_secret }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Installation Instructions -->
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6">
            <h3 class="text-lg font-semibold mb-4 text-blue-900 dark:text-blue-100">Installation Instructions</h3>
            <div class="space-y-4 text-sm">
                <div>
                    <h4 class="font-semibold text-blue-800 dark:text-blue-200">For Hetzner Cloud (Automated):</h4>
                    <ol class="list-decimal list-inside mt-2 space-y-1 text-blue-700 dark:text-blue-300">
                        <li>Copy the "Cloud-Init" script from the tab below</li>
                        <li>Create a new server in Hetzner Cloud Console</li>
                        <li>Paste the script in the "Cloud config" section</li>
                        <li>Launch the server - it will auto-configure</li>
                    </ol>
                </div>
                <div>
                    <h4 class="font-semibold text-blue-800 dark:text-blue-200">For Manual Installation (Any Server):</h4>
                    <ol class="list-decimal list-inside mt-2 space-y-1 text-blue-700 dark:text-blue-300">
                        <li>SSH into your Ubuntu 22.04 server as root</li>
                        <li>Copy the "Install Script" from the tab below</li>
                        <li>Save it as install.sh: <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">nano install.sh</code></li>
                        <li>Make it executable: <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">chmod +x install.sh</code></li>
                        <li>Run it: <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">./install.sh</code></li>
                        <li>Monitor the installation: <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">tail -f /var/log/ef-streaming-install.log</code></li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Script Tabs -->
        <div x-data="{ activeTab: @entangle('activeTab') }">
            <!-- Tab Navigation -->
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex space-x-8">
                    <button
                        @click="activeTab = 'install'"
                        :class="activeTab === 'install' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    >
                        Install Script
                    </button>
                    <button
                        @click="activeTab = 'cloudinit'"
                        :class="activeTab === 'cloudinit' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    >
                        Cloud-Init
                    </button>
                    <button
                        @click="activeTab = 'nginx'"
                        :class="activeTab === 'nginx' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    >
                        NGINX Config
                    </button>
                    <button
                        @click="activeTab = 'srs'"
                        :class="activeTab === 'srs' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    >
                        SRS Config
                    </button>
                    <button
                        @click="activeTab = 'docker'"
                        :class="activeTab === 'docker' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm transition-colors"
                    >
                        Docker Compose
                    </button>
                </nav>
            </div>

            <!-- Tab Content -->
            <div class="mt-6">
                <!-- Install Script Tab -->
                <div x-show="activeTab === 'install'" x-cloak>
                    <div class="relative">
                        <button
                            onclick="copyToClipboard('install-script')"
                            class="absolute top-2 right-2 bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded text-sm transition-colors"
                        >
                            Copy
                        </button>
                        <pre id="install-script" class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs font-mono">{{ $installScript }}</pre>
                    </div>
                </div>

                <!-- Cloud-Init Tab -->
                <div x-show="activeTab === 'cloudinit'" x-cloak>
                    <div class="relative">
                        <button
                            onclick="copyToClipboard('cloudinit-script')"
                            class="absolute top-2 right-2 bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded text-sm transition-colors"
                        >
                            Copy
                        </button>
                        <pre id="cloudinit-script" class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs font-mono">{{ $cloudInitScript }}</pre>
                    </div>
                </div>

                <!-- NGINX Config Tab -->
                <div x-show="activeTab === 'nginx'" x-cloak>
                    <div class="relative">
                        <button
                            onclick="copyToClipboard('nginx-config')"
                            class="absolute top-2 right-2 bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded text-sm transition-colors"
                        >
                            Copy
                        </button>
                        <pre id="nginx-config" class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs font-mono">{{ $nginxConfig }}</pre>
                    </div>
                </div>

                <!-- SRS Config Tab -->
                <div x-show="activeTab === 'srs'" x-cloak>
                    <div class="relative">
                        <button
                            onclick="copyToClipboard('srs-config')"
                            class="absolute top-2 right-2 bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded text-sm transition-colors"
                        >
                            Copy
                        </button>
                        <pre id="srs-config" class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs font-mono">{{ $srsConfig }}</pre>
                    </div>
                </div>

                <!-- Docker Compose Tab -->
                <div x-show="activeTab === 'docker'" x-cloak>
                    <div class="relative">
                        <button
                            onclick="copyToClipboard('docker-config')"
                            class="absolute top-2 right-2 bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded text-sm transition-colors"
                        >
                            Copy
                        </button>
                        <pre id="docker-config" class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs font-mono">{{ $dockerComposeConfig }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                // Show success notification
                window.$wireui.notify({
                    title: 'Copied!',
                    description: 'Content copied to clipboard',
                    icon: 'success'
                });
            }).catch(err => {
                console.error('Failed to copy:', err);
                // Fallback method
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            });
        }
    </script>
</x-filament-panels::page>