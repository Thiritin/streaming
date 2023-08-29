<script setup>
import StreamStatusWrapper from "@/Components/Livestream/StatusPages/Components/StreamStatusWrapper.vue";

const props = defineProps({
    listeners: {
        type: String,
        required: true,
    },
});
import FaWifiSlashIcon from "@/Components/Icons/FaWifiSlashIcon.vue";
import StatusTitle from "@/Components/Livestream/StatusPages/Components/StatusTitle.vue";
import StatusDescription from "@/Components/Livestream/StatusPages/Components/StatusDescription.vue";
</script>

<template>
    <StreamStatusWrapper>
        <FaWifiSlashIcon class="fill-current text-[8rem] md:text-[12rem] block mx-auto mb-8"></FaWifiSlashIcon>
        <StatusTitle>Technical Issues</StatusTitle>
        <StatusDescription class="mb-4">There are currently technical
            issues with the stream. Please stand by, this site will be automatically reloaded
            once
            the
            stream is back online.
        </StatusDescription>
        <StatusDescription class="text-lg">
            There are <span class="text-xl px-0.5">{{ props.listeners }}</span> people waiting for the
            stream to come back online.
        </StatusDescription>
        <div v-if="listeners > 200" class="text-xs text-primary-300">(Oh my... that is a lot of
            people waiting)
        </div>
    </StreamStatusWrapper>
</template>

<style scoped>

</style>
