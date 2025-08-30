<script setup>

import FaVideoIcon from "@/Components/Icons/FaVideoIcon.vue";
import StreamStatusWrapper from "@/Components/Livestream/StatusPages/Components/StreamStatusWrapper.vue";
import StatusTitle from "@/Components/Livestream/StatusPages/Components/StatusTitle.vue";
import StatusDescription from "@/Components/Livestream/StatusPages/Components/StatusDescription.vue";
</script>

<template>
    <StreamStatusWrapper>
        <FaVideoIcon class="fill-current text-[6rem] block mx-auto mb-8"></FaVideoIcon>
        <StatusTitle>The stream is currently offline.</StatusTitle>
        <StatusDescription>This page automatically reloads, once the stream is back online.</StatusDescription>
    </StreamStatusWrapper>
</template>

<style>

</style>
