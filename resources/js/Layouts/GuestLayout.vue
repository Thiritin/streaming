<script>
import ApplicationLogo from '@/Components/Logo.vue';
import {Link} from '@inertiajs/vue3';

export default {
    components: {ApplicationLogo, Link},
    data() {
        return {
            navigation: {
                main: [

                    {
                        name: 'Support',
                        href: 'https://help.eurofurence.org/contact/',
                        newTab: true,
                    },

                    {
                        name: 'Imprint',
                        href: 'https://help.eurofurence.org/legal/imprint',
                        newTab: true,
                    },

                    {
                        name: 'Privacy',
                        href: 'https://help.eurofurence.org/legal/privacy',
                        newTab: true,
                    },
                ],
            },
        }
    }
};
</script>

<template>
    <div class="min-h-screen w-full bg-white flex page">
        <!-- Logo -->
        <div class="bg-primary-700 hidden lg:block w-full relative overflow-hidden flex justify-center items-center">
            <video class="block w-full h-full object-cover top-0 left-0 absolute brightness-75" loop="loop" muted="muted" autoplay="autoplay" playsinline>
                <source src="../../assets/bg.mp4" type="video/mp4">
            </video>
        </div>
        <!-- Page Content -->
        <div
            class="flex-1 flex flex-col bg-primary-900 items-center p-4 sm:px-6 lg:flex-none lg:px-20 xl:px-12">
            <!-- Spacer -->
            <div class="h-[25%]"></div>
            <!-- Slot Content -->
            <div class="flex-auto mx-auto w-full max-w-sm lg:w-96">
                <transition name="page">
                    <div>
                        <slot></slot>
                    </div>
                </transition>
            </div>
            <!-- Footer Content -->
            <div class="pt-8">
                <nav aria-label="Footer" class="-mx-5 -my-2 flex flex-wrap items-center justify-center">
                    <div v-for="item in navigation.main" :key="item.name" class="px-5 py-2">
                        <Link v-if="item.href == null" :href="item.link"
                              :target="[item.newTab ? '_blank' : '_top']"
                              class="text-base text-primary-500 hover:text-primary-400"> {{
                                item.name
                            }}
                        </Link>
                        <a v-else :href="item.href" :target="[item.newTab ? '_blank' : '_top']"
                           class="text-base text-primary-500 hover:text-primary-400"> {{
                                item.name
                            }} </a>
                    </div>
                </nav>
            </div>
        </div>
    </div>
</template>
<style>
.page-enter-active {
    transition: opacity .1s ease-in;
    opacity: 0;
}

.page-enter {
    opacity: 0;
}

.page-enter-to {
    opacity: 1;
}

.page * {
    transition-property: color, background-color, border-color, text-decoration-color, fill, stroke;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}

</style>
