<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class FfmpegCallbackController extends Controller
{
    /**
     * Handle stream publish event - start FFmpeg HLS generation
     */
    public function onPublish(Request $request)
    {
        $app = $request->input('app');
        $stream = $request->input('stream');
        
        // Only process 'live' app (transcoded outputs)
        if ($app !== 'live') {
            return response()->json(['code' => 0]);
        }
        
        // Skip quality variants (only process base streams)
        if (preg_match('/_(fhd|hd|sd|ld)$/', $stream)) {
            return response()->json(['code' => 0]);
        }
        
        Log::info('Starting FFmpeg HLS generation', [
            'app' => $app,
            'stream' => $stream,
        ]);
        
        // Signal the FFmpeg manager to check for new streams
        // Or directly start FFmpeg process here if preferred
        $this->signalFfmpegManager();
        
        return response()->json(['code' => 0]);
    }
    
    /**
     * Handle stream unpublish event - stop FFmpeg HLS generation
     */
    public function onUnpublish(Request $request)
    {
        $app = $request->input('app');
        $stream = $request->input('stream');
        
        // Only process 'live' app
        if ($app !== 'live') {
            return response()->json(['code' => 0]);
        }
        
        // Skip quality variants
        if (preg_match('/_(fhd|hd|sd|ld)$/', $stream)) {
            return response()->json(['code' => 0]);
        }
        
        Log::info('Stopping FFmpeg HLS generation', [
            'app' => $app,
            'stream' => $stream,
        ]);
        
        // Signal the FFmpeg manager to check for removed streams
        $this->signalFfmpegManager();
        
        return response()->json(['code' => 0]);
    }
    
    /**
     * Signal the FFmpeg manager to recheck streams
     */
    private function signalFfmpegManager()
    {
        // Touch a file that the manager watches, or send a signal
        $signalFile = '/var/www/html/hls/check_streams';
        @touch($signalFile);
        
        // Alternative: Send HTTP request to FFmpeg manager if it has an API
        // Or use Redis pub/sub for real-time notifications
    }
}