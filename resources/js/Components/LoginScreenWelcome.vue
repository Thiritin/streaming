<template>
  <!-- Spacing is left to the caller so this can sit in a gapped column. -->
  <div>
    <h1 class="font-semibold text-5xl text-primary-300">{{ title }}</h1>
    <h2 v-if="subTitle" class="text-primary-400 text-3xl">{{ subTitle }}</h2>
  </div>
</template>
<script>
export default {
  name: 'LoginScreenWelcome',
  props: {
    title: {
      required: false
    },
    subTitle: {
      required: false
    },
  }
}
</script>
