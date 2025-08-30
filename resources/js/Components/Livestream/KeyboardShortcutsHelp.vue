<template>
    <transition name="modal">
        <div v-if="visible" class="modal-overlay" @click.self="$emit('close')">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Keyboard Shortcuts</h2>
                    <button @click="$emit('close')" class="close-btn">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                
                <div class="shortcuts-grid">
                    <div class="shortcut-section">
                        <h3>Playback</h3>
                        <div class="shortcut-item">
                            <kbd>Space</kbd> or <kbd>K</kbd>
                            <span>Play / Pause</span>
                        </div>
                    </div>

                    <div class="shortcut-section">
                        <h3>Volume</h3>
                        <div class="shortcut-item">
                            <kbd>M</kbd>
                            <span>Toggle mute</span>
                        </div>
                        <div class="shortcut-item">
                            <kbd>↑</kbd>
                            <span>Volume up</span>
                        </div>
                        <div class="shortcut-item">
                            <kbd>↓</kbd>
                            <span>Volume down</span>
                        </div>
                    </div>

                    <div class="shortcut-section">
                        <h3>Display</h3>
                        <div class="shortcut-item">
                            <kbd>F</kbd>
                            <span>Toggle fullscreen</span>
                        </div>
                        <div class="shortcut-item">
                            <kbd>I</kbd>
                            <span>Stats overlay</span>
                        </div>
                    </div>

                    <div class="shortcut-section">
                        <h3>Other</h3>
                        <div class="shortcut-item">
                            <kbd>?</kbd>
                            <span>Show this help</span>
                        </div>
                        <div class="shortcut-item">
                            <kbd>Esc</kbd>
                            <span>Exit fullscreen</span>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <p class="hint">Press any key to close</p>
                </div>
            </div>
        </div>
    </transition>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';

defineProps({
    visible: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close']);

const handleKeyPress = (e) => {
    // Close on any key press
    emit('close');
};

onMounted(() => {
    document.addEventListener('keydown', handleKeyPress);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeyPress);
});
</script>

<style scoped>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(5px);
}

.modal-content {
    background: #1a1a1a;
    border-radius: 12px;
    padding: 0;
    width: 90%;
    max-width: 700px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.02);
}

.modal-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: white;
}

.close-btn {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.6);
    cursor: pointer;
    padding: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}

.close-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.shortcuts-grid {
    padding: 24px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 32px;
    max-height: calc(90vh - 140px);
    overflow-y: auto;
}

.shortcut-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.shortcut-section h3 {
    margin: 0 0 8px 0;
    font-size: 14px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.5);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.shortcut-item {
    display: flex;
    align-items: center;
    gap: 12px;
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
}

.shortcut-item kbd {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    padding: 4px 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    font-weight: 600;
    color: white;
    min-width: 28px;
    text-align: center;
    display: inline-block;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.shortcut-item span {
    flex: 1;
    color: rgba(255, 255, 255, 0.8);
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.02);
}

.hint {
    margin: 0;
    font-size: 13px;
    color: rgba(255, 255, 255, 0.5);
    text-align: center;
}

/* Scrollbar styling */
.shortcuts-grid::-webkit-scrollbar {
    width: 8px;
}

.shortcuts-grid::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
}

.shortcuts-grid::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
}

.shortcuts-grid::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3);
}

/* Modal animation */
.modal-enter-active, .modal-leave-active {
    transition: all 0.3s ease;
}

.modal-enter-from, .modal-leave-to {
    opacity: 0;
}

.modal-enter-from .modal-content,
.modal-leave-to .modal-content {
    transform: scale(0.9);
}

/* Mobile responsive */
@media (max-width: 640px) {
    .modal-content {
        width: 95%;
        max-height: 95vh;
    }
    
    .shortcuts-grid {
        grid-template-columns: 1fr;
        gap: 24px;
        padding: 20px;
    }
}
</style>