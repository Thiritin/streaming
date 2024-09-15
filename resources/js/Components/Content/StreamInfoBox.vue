<script setup>

import FaIconUser from "@/Components/Icons/FaIconUser.vue";
import {ref, watch} from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";

const props = defineProps(['listeners', 'status', 'streamUrl']);
const emit = defineEmits(['stream-url-changed']);
const selectedOption = ref(props.streamUrl);

watch(selectedOption, (newValue, oldValue) => {
  if (newValue !== oldValue) {
    changeStreamUrl(newValue);
  }
});

function changeStreamUrl(url) {
  emit('stream-url-changed', url);
}

</script>

<template>
  <div class="bg-primary-800">
    <div class="px-4 lg:px-12 py-8 text-primary-200 flex flex-col-reverse lg:flex-row justify-between">
      <div class="max-w-screen-lg">
        <div class="h1 text-2xl mb-4">Eurofurence 28 Live</div>

        <p class="mb-4">
          🎉 This is the official Eurofurence stream, bringing the convention experience
          straight
          to your hotel room! 🎉
        </p>

        <p class="mb-4">
          Join us from the comfort of your own space and never miss a moment of the fun.
          We have an exciting lineup of
          major events planned just for you. Prepare to be dazzled by the spectacular Dance
          Battles, where talented
          fursuiters showcase their moves and compete for glory.
        </p>

        <p class="mb-4">
          But that's not all! The Paw Pet Show is sure to melt your heart as adorable critters
          take the stage to impress
          and entertain. And of course, we couldn't forget about the Evening Dances, where the
          party comes alive with
          music, dancing, and the electric atmosphere that only Eurofurence can provide.
        </p>

        <p class="mb-4">
          So sit back, relax, and immerse yourself in the magic of Eurofurence 28. Let's
          make
          unforgettable memories
          together! 🐾✨
        </p>

        <div class="flex gap-3">
          <a
              href="https://t.me/efprimehelp"
              class="py-1 px-4 rounded-lg text-primary-300 font-semibold bg-primary-500 enabled:hover:bg-primary-700 transition">
            Get Help
          </a>
        </div>
      </div>
      <div class="lg:min-w-[200px]">
        <div class="flex gap-6 mb-5 justify-center lg:justify-end">
          <div class="flex gap-3">
            <div class="self-center bg-primary-900 px-2 py-1 rounded-lg" v-if="status === 'online'">
              Live!
            </div>
            <div class="flex items-center justify-center gap-2">
              <FaIconUser class="fill-current"></FaIconUser>
              {{ props.listeners }}
            </div>
          </div>
          <div>
            <!-- Select your Bitrate dropdown -->
            <label class="text-xs text-primary-200">Select your resolution</label>
            <select
                v-model="selectedOption"
                class="block w-full mt-1 rounded-md bg-primary-700 border-primary-300order-primary-600 text-primary-100 focus:border-primary-600 focus:ring focus:ring-primary-600 transition ease-in-out duration-150 sm:text-sm sm:leading-5">
              <option value="auto">Auto</option>
              <option value="fhd">1080p</option>
              <option value="hd">720p</option>
              <option value="sd">480p</option>
              <option value="ld">360p</option>
              <option value="audio_hd">Only Audio (HD)</option>
              <option value="audio_sd">Only Audio (SD)</option>
            </select>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>
