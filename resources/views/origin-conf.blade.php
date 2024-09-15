listen              1935;
max_connections     {!! $maxConnections !!};
server_id           {!! $serverId !!};

srs_log_tank        console;
daemon              off;

http_api {
    enabled         on;
    listen          1985;
}

http_server {
    enabled         on;
    listen          8080;
    dir             ./objs/nginx/html;
}

rtc_server {
    enabled off;
    listen 8000;
    # @see https://ossrs.net/lts/zh-cn/docs/v4/doc/webrtc#config-candidate
    candidate $CANDIDATE;
}

vhost __defaultVhost__ {
    http_remux {
        enabled     on;
        mount       [vhost]/[app]/[stream].flv;
    }

    rtc {
        enabled     off;
    }

    play {
        gop_cache       on;
        mw_latency      1800;
    }

    transcode {
        enabled on;
        ffmpeg      ./objs/ffmpeg/bin/ffmpeg;

        @if(isset($twitchUrl))
engine twitch_live {
            enabled on;
            vcodec libx264;
            vbitrate 1200;
            vfps 25;
            vwidth 854;
            vheight 480;
            vthreads 4;
            vprofile main;
            vpreset medium;
            acodec libfdk_aac;
            abitrate 70;
            asample_rate 44100;
            achannels 2;
            output {!! $twitchUrl !!};
        }
        @endif

        @if(isset($vrchatUrl))
engine vrchat_live {
            enabled on;
            vcodec copy;
            vbitrate 6000;
            vfps 30;
            vwidth 1920;
            vheight 1080;
            vthreads 14;
            vprofile high;
            vpreset slow;
            vparams {
                t 100;
                g 1;  #Keyframe 
                bf 2;  # B-Frames
                }
            acodec libfdk_aac;
            abitrate 160;
            asample_rate 48000;
            achannels 2;
            output {!! $vrchatUrl !!};
        }
        @endif

        engine fhd {
            enabled         on;
            vcodec          copy;
            acodec          copy;
            output          rtmp://127.0.0.1:[port]/[app]?vhost=[vhost]/[stream]_[engine];
        }

        engine audio_hd {
            enabled         on;
            vcodec          vn;
            acodec          libfdk_aac;
            abitrate        120;
            asample_rate    44100;
            achannels       2;
            aparams {
            }
            output rtmp://127.0.0.1:[port]/[app]?vhost=[vhost]/[stream]_[engine];
        }

        engine audio_sd {
            enabled         on;
            vcodec          vn;
            acodec          libfdk_aac;
            abitrate        70;
            asample_rate    44100;
            achannels       2;
            aparams {
            }
            output rtmp://127.0.0.1:[port]/[app]?vhost=[vhost]/[stream]_[engine];
        }

        engine hd {
            enabled on;
            vcodec libx264;
            vbitrate 1200;
            vfps 25;
            vwidth 1280;
            vheight 720;
            vthreads 4;
            vprofile main;
            vpreset medium;
            acodec libfdk_aac;
            abitrate 70;
            asample_rate 44100;
            achannels 2;
            output rtmp://127.0.0.1:[port]/[app]?vhost=[vhost]/[stream]_[engine];
        }

        engine sd {
            enabled on;
            vcodec libx264;
            vbitrate 1200;
            vfps 25;
            vwidth 854;
            vheight 480;
            vthreads 4;
            vprofile main;
            vpreset medium;
            acodec libfdk_aac;
            abitrate 70;
            asample_rate 44100;
            achannels 2;
            output rtmp://127.0.0.1:[port]/[app]?vhost=[vhost]/[stream]_[engine];
        }


        engine ld {
            enabled on;
            vcodec libx264;
            vbitrate 1200;
            vfps 25;
            vwidth 640;
            vheight 360;
            vthreads 2;
            vprofile main;
            vpreset fast;
            acodec libfdk_aac;
            abitrate 70;
            asample_rate 44100;
            achannels 2;
            output rtmp://127.0.0.1:[port]/[app]?vhost=[vhost]/[stream]_[engine];
        }
    }
}
