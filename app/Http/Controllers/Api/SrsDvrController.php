<?php

namespace App\Http\Controllers\Api;

use App\Models\DvrRecording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class SrsDvrController extends Controller
{
    /**
     * Handle DVR callback from SRS
     * Called when a DVR segment is created
     */
    public function handleDvrCallback(Request $request)
    {
        try {
            // Log the DVR event
            Log::info('DVR callback received', $request->all());
            
            // Extract DVR information
            $data = $request->all();
            
            // Parse the file path to extract metadata
            $filePath = $data['file'] ?? '';
            $app = $data['app'] ?? '';
            $stream = $data['stream'] ?? '';
            $vhost = $data['vhost'] ?? '__defaultVhost__';
            $clientId = $data['client_id'] ?? null;
            $ip = $data['ip'] ?? '';
            $action = $data['action'] ?? '';
            
            // Store DVR recording information in database (optional)
            if ($filePath) {
                $this->storeDvrRecording([
                    'file_path' => $filePath,
                    'app' => $app,
                    'stream' => $stream,
                    'vhost' => $vhost,
                    'client_id' => $clientId,
                    'client_ip' => $ip,
                    'action' => $action,
                    'created_at' => now(),
                ]);
            }
            
            // Return success response
            return response()->json([
                'code' => 0,
                'msg' => 'ok'
            ]);
            
        } catch (\Exception $e) {
            Log::error('DVR callback error: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e
            ]);
            
            // SRS expects code 0 for success, non-zero for error
            return response()->json([
                'code' => 1,
                'msg' => 'error: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Store DVR recording information
     */
    private function storeDvrRecording(array $data)
    {
        try {
            // You can create a DvrRecording model to track recordings
            // For now, just log it
            Log::info('DVR recording created', $data);
            
            // Optional: Store in database
            // DvrRecording::create($data);
            
            // Optional: Dispatch job for post-processing
            // ProcessDvrRecording::dispatch($data);
            
        } catch (\Exception $e) {
            Log::error('Failed to store DVR recording: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle S3 upload webhook from DVR uploader service
     */
    public function handleUploadWebhook(Request $request)
    {
        try {
            Log::info('DVR S3 upload webhook received', $request->all());
            
            $data = $request->all();
            
            // Extract information
            $s3Bucket = $data['s3_bucket'] ?? '';
            $s3Key = $data['s3_key'] ?? '';
            $s3Url = $data['s3_url'] ?? '';
            $app = $data['app'] ?? '';
            $stream = $data['stream'] ?? '';
            $date = $data['date'] ?? '';
            
            // Optional: Update recording status in database
            // Optional: Send notifications
            // Optional: Trigger VOD processing
            
            return response()->json([
                'status' => 'success',
                'message' => 'Upload webhook processed'
            ]);
            
        } catch (\Exception $e) {
            Log::error('DVR upload webhook error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}