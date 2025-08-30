<script setup>

import FaCircleNotchIcon from "@/Components/Icons/FaCircleNotchIcon.vue";
import StreamStatusWrapper from "@/Components/Livestream/StatusPages/Components/StreamStatusWrapper.vue";
import StatusDescription from "@/Components/Livestream/StatusPages/Components/StatusDescription.vue";
import StatusTitle from "@/Components/Livestream/StatusPages/Components/StatusTitle.vue";
</script>

<template>
    <StreamStatusWrapper>
        <FaCircleNotchIcon
            class="animate-spin fill-current text-[12rem] block mx-auto mb-8"></FaCircleNotchIcon>
        <StatusTitle>Finding a Server...</StatusTitle>
        <StatusDescription class="mb-4">
            We are currently increasing the capacity of our datacenter. This may take a few
            minutes
        </StatusDescription>
        <StatusDescription>
            You will be automatically redirected once a server is available.
        </StatusDescription>
    </StreamStatusWrapper>
</template>

<style>

</style>
