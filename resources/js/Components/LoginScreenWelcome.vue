<template>
  <div class="mt-12">
    <h1 class="font-semibold text-5xl text-primary-300">{{ title }}</h1>
    <h2 class="text-primary-400 text-3xl">{{ subTitle }}</h2>
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
