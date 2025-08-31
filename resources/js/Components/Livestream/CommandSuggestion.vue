<script setup>
import { ref, computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    visible: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue', 'close', 'select']);

const page = usePage();
const selectedIndex = ref(0);

// Get available commands from Inertia props
const availableCommands = computed(() => {
    return page.props.chat?.commands || [];
});

// Filter commands based on current input
const filteredCommands = computed(() => {
    if (!props.modelValue || !props.modelValue.startsWith('/')) {
        return [];
    }

    const searchTerm = props.modelValue.slice(1).toLowerCase();

    if (searchTerm === '') {
        return availableCommands.value;
    }

    return availableCommands.value.filter(cmd => {
        const matchesName = cmd.name.toLowerCase().startsWith(searchTerm);
        const matchesAlias = cmd.aliases?.some(alias =>
            alias.toLowerCase().startsWith(searchTerm)
        );
        return matchesName || matchesAlias;
    });
});

// Reset selected index when filtered list changes
watch(filteredCommands, () => {
    selectedIndex.value = 0;
});

function selectCommand(command) {
    emit('select', command);
    emit('update:modelValue', '/' + command.name + ' ');
    emit('close');
}

function handleKeyDown(event) {
    if (!props.visible) return;
    
    // If no filtered commands, don't handle any keys
    if (filteredCommands.value.length === 0) {
        if (event.key === 'Escape') {
            event.preventDefault();
            emit('close');
        }
        return;
    }

    switch(event.key) {
        case 'ArrowUp':
            event.preventDefault();
            selectedIndex.value = Math.max(0, selectedIndex.value - 1);
            break;
        case 'ArrowDown':
            event.preventDefault();
            selectedIndex.value = Math.min(filteredCommands.value.length - 1, selectedIndex.value + 1);
            break;
        case 'Tab':
            event.preventDefault();
            if (filteredCommands.value[selectedIndex.value]) {
                selectCommand(filteredCommands.value[selectedIndex.value]);
            }
            break;
        case 'Enter':
            // Only prevent default and select if we have a valid selection
            if (filteredCommands.value[selectedIndex.value]) {
                event.preventDefault();
                selectCommand(filteredCommands.value[selectedIndex.value]);
            }
            // Otherwise let the enter key propagate normally
            break;
        case 'Escape':
            event.preventDefault();
            emit('close');
            break;
    }
}

// Expose methods for parent to use
defineExpose({
    handleKeyDown,
    hasFilteredCommands: () => filteredCommands.value.length > 0
});
</script>

<template>
    <transition name="fade-slide">
        <div v-if="visible && filteredCommands.length > 0"
             class="absolute bottom-full mb-2 left-0 right-0 bg-primary-800 rounded-lg shadow-xl border border-primary-700 max-h-64 overflow-y-auto z-50">
            <div class="p-2">
                <div class="text-xs text-primary-400 uppercase tracking-wider mb-2 px-2">
                    Available Commands
                </div>
                <div v-for="(command, index) in filteredCommands"
                     :key="command.name"
                     @click="selectCommand(command)"
                     :class="[
                         'cursor-pointer rounded px-2 py-2 mb-1 transition-colors',
                         index === selectedIndex ? 'bg-primary-600 text-white' : 'hover:bg-primary-700 text-primary-300'
                     ]">
                    <div class="flex items-start">
                        <div class="flex-1">
                            <div class="font-semibold">
                                /{{ command.name }}
                                <span v-if="command.aliases && command.aliases.length > 0" class="text-xs text-primary-400 ml-1">
                                    (alias: {{ command.aliases.join(', ') }})
                                </span>
                            </div>
                            <div class="text-xs text-primary-400 mt-1">
                                {{ command.description }}
                            </div>
                            <div class="text-xs font-mono mt-1" :class="index === selectedIndex ? 'text-primary-200' : 'text-primary-500'">
                                {{ command.syntax }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </transition>
</template>

<style>
.fade-slide-enter-active,
.fade-slide-leave-active {
    transition: all 0.2s ease;
}

.fade-slide-enter-from,
.fade-slide-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
</style>
