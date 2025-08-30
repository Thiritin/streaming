<script setup>
import StreamStatusWrapper from "@/Components/Livestream/StatusPages/Components/StreamStatusWrapper.vue";

const emit = defineEmits(["endStreamOnOtherDevice"]);
import PrimaryButton from "@/Components/PrimaryButton.vue";
import StatusTitle from "@/Components/Livestream/StatusPages/Components/StatusTitle.vue";
import StatusDescription from "@/Components/Livestream/StatusPages/Components/StatusDescription.vue";
</script>

<template>
    <StreamStatusWrapper>
        <StatusTitle>End stream on other device?</StatusTitle>
        <StatusDescription>To preserve bandwidth, we are only allowing one concurrent stream per user.</StatusDescription>
        <primary-button class="mt-4" @click="$emit('endStreamOnOtherDevice')">Continue here</primary-button>
    </StreamStatusWrapper>
</template>

<style>

</style>
