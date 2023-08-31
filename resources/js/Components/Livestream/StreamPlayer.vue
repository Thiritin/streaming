<script setup>

import VueFlvPlayer from "@/Components/VueFlvPlayer.vue";
const props = defineProps({
    streamUrl: String,
})
let mediaDataSource = {
    isLive: true,
}
</script>

<template>
    <VueFlvPlayer :autoplay="true"
                  :isLive="true"
                  :controls="true" :muted="false"
                  ref="livePlayer"
                  :media-data-source="mediaDataSource"
                  :source="streamUrl + '&client=web'" type="flv"/>
</template>

<style scoped>

</style>
