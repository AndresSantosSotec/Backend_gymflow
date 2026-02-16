<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FingerprintService;
use Illuminate\Http\Request;

class FingerprintStatusController extends Controller
{
    /**
     * Get fingerprint device status
     */
    public function deviceStatus()
    {
        $fingerprintService = new FingerprintService();
        $status = $fingerprintService->getDeviceStatus();

        return response()->json($status);
    }

    /**
     * Capture fingerprint from device
     */
    public function capture(Request $request)
    {
        $fingerprintService = new FingerprintService();
        $result = $fingerprintService->captureFingerprint();

        if (!$result['success']) {
            return response()->json([
                'error' => $result['error'] ?? 'Failed to capture fingerprint',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'fingerprint_template' => $result['data']['template'] ?? null,
            'quality' => $result['data']['quality'] ?? 0,
            'message' => 'Fingerprint captured successfully',
        ]);
    }

    /**
     * List all fingerprints registered in device
     */
    public function listFingerprints()
    {
        $fingerprintService = new FingerprintService();
        $result = $fingerprintService->listFingerprints();

        if (!$result['success']) {
            return response()->json([
                'error' => $result['error'] ?? 'Failed to list fingerprints',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'fingerprints' => $result['fingerprints'],
        ]);
    }

    /**
     * Sync all fingerprints with device
     */
    public function syncAll()
    {
        $fingerprintService = new FingerprintService();
        $result = $fingerprintService->syncAllFingerprints();

        return response()->json($result);
    }

    /**
     * Test connection to fingerprint server
     */
    public function testConnection()
    {
        $fingerprintService = new FingerprintService();
        $result = $fingerprintService->testConnection();

        if ($result['success']) {
            return response()->json([
                'status' => 'connected',
                'message' => $result['message'],
                'version' => $result['version'] ?? 'unknown',
            ]);
        }

        return response()->json([
            'status' => 'disconnected',
            'error' => $result['error'],
            'url' => $result['url'] ?? null,
            'details' => $result['details'] ?? null,
        ], 503);
    }
}
