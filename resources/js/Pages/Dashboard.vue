<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head} from '@inertiajs/vue3';
import VueFlvPlayer from "@/Components/VueFlvPlayer.vue";

export default {
    components: {VueFlvPlayer, Head, AuthenticatedLayout},
    setup() {
        return {
            animated: false,
        }
    },
    mounted() {
        this.animated = true;
    },
    props: {
        personalizedStreamUrl: {
            type: String,
            required: true,
        },
        status: {
            type: String,
            required: true,
        }
    },
    data() {
        return {
            mediaDataSource: {
                isLive: true,
            }
        }
    }
}

</script>

<template>
    <Head title="Stream"/>

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="max-w-7xl mx-auto" v-if="status === 'online'">
                <VueFlvPlayer :autoplay="true" :controls="true" :muted="false" ref="myPlayer"
                              :media-data-source="mediaDataSource"
                              :source="personalizedStreamUrl" type="flv" class="w-full"/>
                <div class="bg-white dark:bg-primary-800 overflow-hidden shadow-sm sm:rounded-b-lg">
                    <div class="p-6 text-primary-900 dark:text-primary-100">
                        <div class="inline bg-gray"></div>
                        Eurofurence 2023 Live!
                    </div>
                    <div class="w-full flex justify-center pb-8">
                    </div>
                </div>
            </div>
            <div class="max-w-7xl mx-auto" v-else-if="status === 'technical_issues'">
                <div class="bg-white dark:bg-primary-800 overflow-hidden shadow-sm sm:rounded-b-lg">
                    <div class="p-6 text-primary-900 dark:text-primary-100 text-center">There are currently technical
                        issues with the stream. Please check back at a later time.
                    </div>
                </div>
            </div>
            <div class="max-w-7xl mx-auto" v-else-if="status === 'offline'">
                <div class="bg-white dark:bg-primary-800 overflow-hidden shadow-sm sm:rounded-b-lg">
                    <div class="p-6 text-primary-900 dark:text-primary-100 text-center">There is currently no stream running.</div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
