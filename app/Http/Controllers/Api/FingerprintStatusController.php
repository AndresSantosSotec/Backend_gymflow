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
                'success'     => false,
                'error'       => $result['error'] ?? 'Failed to capture fingerprint',
                'needs_admin' => $result['needs_admin'] ?? false,
            ], 400);
        }

        $data = $result['data'] ?? [];

        // Pass image through so the frontend can display it
        return response()->json([
            'success'              => true,
            'fingerprint_template' => $data['fingerprint_template'] ?? $data['template'] ?? null,
            'template'             => $data['template'] ?? null,
            'image_base64'         => $data['image_base64'] ?? null,
            'image_mime'           => $data['image_mime'] ?? null,
            'quality'              => $data['quality'] ?? 0,
            'mode'                 => $data['mode'] ?? 'hardware',
            'message'              => $data['message'] ?? 'Fingerprint captured successfully',
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

    /**
     * Get a single stored fingerprint by ID (includes image_base64 for UI display).
     */
    public function show(string $fingerprintId)
    {
        $service = new FingerprintService();
        $result  = $service->getFingerprintById($fingerprintId);

        if (!$result['success']) {
            return response()->json(['error' => $result['error'] ?? 'Not found'], 404);
        }

        $data = $result['data'] ?? [];
        // Never return the raw template bytes to the frontend
        unset($data['template']);

        return response()->json($data);
    }

    /**
     * Verify a fingerprint live (capture + match) and return result + live image.
     */
    public function verifyLive(Request $request)
    {
        $fpId = $request->input('fingerprint_id');
        if (!$fpId) {
            return response()->json(['error' => 'fingerprint_id required'], 422);
        }

        $service = new FingerprintService();
        $result  = $service->verifyLive($fpId);

        if (!$result['success']) {
            return response()->json(['error' => $result['error'] ?? 'Verify failed'], 500);
        }

        return response()->json($result['data']);
    }
}
