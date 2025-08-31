<x-filament-panels::page>
    <div x-data="{ 
        activeTab: @entangle('activeTab'),
        copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                new FilamentNotification()
                    .title('Copied!')
                    .body('Script copied to clipboard')
                    .success()
                    .send();
            });
        }
    }">
        <!-- Tab Navigation -->
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-8 overflow-x-auto" aria-label="Tabs">
                <button @click="activeTab = 'install'"
                    :class="activeTab === 'install' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                    Install Script
                </button>
                <button @click="activeTab = 'cloud-init'"
                    :class="activeTab === 'cloud-init' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                    Cloud-Init
                </button>
                <button @click="activeTab = 'docker-compose'"
                    :class="activeTab === 'docker-compose' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                    Docker Compose
                </button>
                @if($record->type->value === 'origin')
                    <button @click="activeTab = 'nginx-origin'"
                        :class="activeTab === 'nginx-origin' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Nginx (Origin)
                    </button>
                    <button @click="activeTab = 'caddy-origin'"
                        :class="activeTab === 'caddy-origin' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Caddy (Origin)
                    </button>
                    <button @click="activeTab = 'ffmpeg'"
                        :class="activeTab === 'ffmpeg' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        FFmpeg HLS
                    </button>
                @else
                    <button @click="activeTab = 'nginx-edge'"
                        :class="activeTab === 'nginx-edge' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Nginx (Edge)
                    </button>
                    <button @click="activeTab = 'caddy-edge'"
                        :class="activeTab === 'caddy-edge' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                        Caddy (Edge)
                    </button>
                @endif
                <button @click="activeTab = 'srs'"
                    :class="activeTab === 'srs' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition">
                    SRS Config
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div class="mt-6">
            <!-- Install Script Tab -->
            <div x-show="activeTab === 'install'" x-cloak>
                <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Installation Instructions</h3>
                    <ol class="list-decimal list-inside space-y-1 text-sm text-blue-800 dark:text-blue-200">
                        <li>SSH into your Ubuntu 22.04 server as root</li>
                        <li>Copy the script below and save it as <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">install.sh</code></li>
                        <li>Make it executable: <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">chmod +x install.sh</code></li>
                        <li>Run it: <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">./install.sh</code></li>
                    </ol>
                </div>
                <div class="relative">
                    <button @click="copyToClipboard(@js($installScript))"
                        class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </button>
                    <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $installScript }}</code></pre>
                </div>
            </div>

            <!-- Cloud-Init Tab -->
            <div x-show="activeTab === 'cloud-init'" x-cloak>
                <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Hetzner Cloud-Init Usage</h3>
                    <p class="text-sm text-blue-800 dark:text-blue-200">
                        Use this configuration when creating a new server through Hetzner Cloud Console or API.
                        Paste this into the "Cloud config" field when creating a new server.
                    </p>
                </div>
                <div class="relative">
                    <button @click="copyToClipboard(@js($cloudInitScript))"
                        class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </button>
                    <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $cloudInitScript }}</code></pre>
                </div>
            </div>

            <!-- Docker Compose Tab -->
            <div x-show="activeTab === 'docker-compose'" x-cloak>
                <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Docker Compose Configuration</h3>
                    <p class="text-sm text-blue-800 dark:text-blue-200">
                        This file will be automatically downloaded as <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">docker-compose.yml</code> during installation.
                    </p>
                </div>
                <div class="relative">
                    <button @click="copyToClipboard(@js($dockerComposeConfig))"
                        class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </button>
                    <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $dockerComposeConfig }}</code></pre>
                </div>
            </div>

            @if($record->type->value === 'origin')
                <!-- Nginx Origin Tab -->
                <div x-show="activeTab === 'nginx-origin'" x-cloak>
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Origin Nginx Configuration</h3>
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            Nginx configuration for the origin server. Handles authentication and serves HLS files.
                        </p>
                    </div>
                    <div class="relative">
                        <button @click="copyToClipboard(@js($nginxOriginConfig))"
                            class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $nginxOriginConfig }}</code></pre>
                    </div>
                </div>

                <!-- Caddy Origin Tab -->
                <div x-show="activeTab === 'caddy-origin'" x-cloak>
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Origin Caddy Configuration</h3>
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            Caddy configuration for SSL termination on the origin server.
                        </p>
                    </div>
                    <div class="relative">
                        <button @click="copyToClipboard(@js($caddyOriginConfig))"
                            class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $caddyOriginConfig }}</code></pre>
                    </div>
                </div>

                <!-- FFmpeg Tab -->
                <div x-show="activeTab === 'ffmpeg'" x-cloak>
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">FFmpeg HLS Transcoder</h3>
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            FFmpeg container configuration for multi-bitrate HLS transcoding.
                        </p>
                    </div>
                    
                    <h4 class="font-semibold mb-2">Dockerfile:</h4>
                    <div class="relative mb-6">
                        <button @click="copyToClipboard(@js($ffmpegDockerfile))"
                            class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $ffmpegDockerfile }}</code></pre>
                    </div>
                    
                    <h4 class="font-semibold mb-2">Stream Manager Script:</h4>
                    <div class="relative">
                        <button @click="copyToClipboard(@js($ffmpegScript))"
                            class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $ffmpegScript }}</code></pre>
                    </div>
                </div>
            @else
                <!-- Nginx Edge Tab -->
                <div x-show="activeTab === 'nginx-edge'" x-cloak>
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Edge Nginx Configuration</h3>
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            Nginx configuration for edge server. Caches and proxies content from the origin server.
                        </p>
                    </div>
                    <div class="relative">
                        <button @click="copyToClipboard(@js($nginxEdgeConfig))"
                            class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $nginxEdgeConfig }}</code></pre>
                    </div>
                </div>

                <!-- Caddy Edge Tab -->
                <div x-show="activeTab === 'caddy-edge'" x-cloak>
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">Edge Caddy Configuration</h3>
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            Caddy configuration for SSL termination on the edge server.
                        </p>
                    </div>
                    <div class="relative">
                        <button @click="copyToClipboard(@js($caddyEdgeConfig))"
                            class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $caddyEdgeConfig }}</code></pre>
                    </div>
                </div>
            @endif

            <!-- SRS Config Tab -->
            <div x-show="activeTab === 'srs'" x-cloak>
                <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                    <h3 class="font-semibold text-blue-900 dark:text-blue-300 mb-2">SRS Configuration</h3>
                    <p class="text-sm text-blue-800 dark:text-blue-200">
                        Simple Realtime Server (SRS) configuration for {{ $record->type->value }} server.
                    </p>
                </div>
                <div class="relative">
                    <button @click="copyToClipboard(@js($srsConfig))"
                        class="absolute top-2 right-2 p-2 bg-gray-100 dark:bg-gray-700 rounded hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </button>
                    <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto"><code>{{ $srsConfig }}</code></pre>
                </div>
            </div>
        </div>

        <!-- Server Information -->
        <div class="mt-8 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <h3 class="font-semibold mb-2">Server Information</h3>
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Server Type</dt>
                    <dd class="mt-1">{{ ucfirst($record->type->value) }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Server ID</dt>
                    <dd class="mt-1">{{ $record->id }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Hostname</dt>
                    <dd class="mt-1">{{ $record->hostname ?: 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Port</dt>
                    <dd class="mt-1">{{ $record->port }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Shared Secret</dt>
                    <dd class="mt-1 font-mono text-xs break-all">{{ $record->shared_secret }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1">
                        <span class="px-2 py-1 text-xs rounded-full 
                            @if($record->status === 'active') bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100
                            @elseif($record->status === 'provisioning') bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100
                            @else bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300
                            @endif">
                            {{ ucfirst($record->status) }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</x-filament-panels::page>