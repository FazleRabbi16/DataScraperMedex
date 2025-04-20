<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Validator;

class HelperController extends Controller
{
    // check duplicat url
    public function urlChecker(Request $request)
    {
        // Step 1: Validate the input file
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:txt',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Step 2: Read the content of the uploaded file
        $file = $request->file('file');
        $urls = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Step 3: Validate the URLs
        $validUrls = [];
        foreach ($urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                // If the URL is not valid, skip it
                continue;
            }
            $validUrls[] = $url;
        }

        if (count($validUrls) == 0) {
            return response()->json(['errors' => 'No valid URLs found in the file.'], 422);
        }

        // File paths
        $uniqueFile = storage_path('app/uniqueUrl.txt');
        $duplicateFile = storage_path('app/duplicate.txt');

        // Read already stored unique URLs
        $existingUnique = file_exists($uniqueFile) 
            ? file($uniqueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) 
            : [];

        $uniqueAdded = 0;
        $duplicatesFound = 0;
        $currentDuplicates = []; // to avoid writing the same URL more than once per request

        // Step 4: Process valid URLs
        foreach ($validUrls as $url) {
            if (in_array($url, $existingUnique)) {
                // Already known URL = duplicate
                if (!in_array($url, $currentDuplicates)) {
                    file_put_contents($duplicateFile, $url . PHP_EOL, FILE_APPEND);
                    $currentDuplicates[] = $url;
                    $duplicatesFound++;
                }
            } else {
                // First time seeing this URL — save to unique
                file_put_contents($uniqueFile, $url . PHP_EOL, FILE_APPEND);
                $existingUnique[] = $url;
                $uniqueAdded++;
            }
        }

        return response()->json([
            'message' => 'URL check completed.',
            'unique_added' => $uniqueAdded,
            'duplicates_found' => $duplicatesFound,
        ]);
    }
    // capsule-tablet-url checker
    public function urlCheckerTabCap(Request $request)
    {
        // Step 1: Validate the uploaded file
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:txt',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Step 2: Read URLs from the uploaded file
        $file = $request->file('file');
        $urls = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Step 3: Prepare arrays to store categorized URLs
        $tabCapUrls = [];
        $nonTabCapUrls = [];

        foreach ($urls as $url) {
            $url = trim($url);

            // Parse URL and get the last part after the last slash
            $parsedPath = parse_url($url, PHP_URL_PATH);
            $lastPart = strtolower(basename($parsedPath));

            // Check if last part contains 'tablet' or 'capsule'
            if (strpos($lastPart, 'tablet') !== false || strpos($lastPart, 'capsule') !== false) {
                $tabCapUrls[] = $url;
            } else {
                $nonTabCapUrls[] = $url;
            }
        }

        // Step 4: Save results to new text files
        $tabCapPath = storage_path('app/tab-cap.txt');
        $nonTabCapPath = storage_path('app/non-tab-cap.txt');

        file_put_contents($tabCapPath, implode(PHP_EOL, $tabCapUrls));
        file_put_contents($nonTabCapPath, implode(PHP_EOL, $nonTabCapUrls));

        // Step 5: Return JSON response with counts
        return response()->json([
            'message' => 'URL processing completed.',
            'tab_cap_count' => count($tabCapUrls),
            'non_tab_cap_count' => count($nonTabCapUrls),
            'tab_cap_file' => 'tab-cap.txt',
            'non_tab_cap_file' => 'non-tab-cap.txt'
        ]);
    }
    public function uploadUrl(Request $request)
    {
        $request->validate([
            'url_file' => 'required|file|mimes:txt|max:10240',
        ]);
    
        if ($request->hasFile('url_file')) {
            $file = $request->file('url_file');
            $filePath = 'url.txt';
            Storage::put($filePath, file_get_contents($file));
    
            return response()->json([
                'success' => true,
                'message' => 'File uploaded and replaced successfully.'
            ], 200);
        }
    
        return response()->json([
            'success' => false,
            'message' => 'Please upload a valid file.'
        ], 400);
    }
    //check product quantity adder, like 80mg+20mg , () etc
    public function quantityChecker(Request $request)
{
    // Step 1: Validate the uploaded file
    $validator = Validator::make($request->all(), [
        'file' => 'required|file|mimes:txt,json',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Step 2: Read and parse JSON from uploaded file
    $file = $request->file('file');
    $content = file_get_contents($file->getRealPath());
    $products = json_decode($content, true);
    $totalSubmitted = count($products);
    if (!is_array($products)) {
        return response()->json(['error' => 'Invalid JSON format'], 400);
    }

    // Step 3: Filter products where 'quantity' contains special characters
    $filtered = array_filter($products, function ($product) {
        if (!isset($product['quantity'])) return false;
        return preg_match('/[+\/\(\)"]/', $product['quantity']);
    });

    // Step 4: Save filtered objects to a file (unchanged data)
    $savePath = storage_path('app/filter_quantity_checked.txt');
    file_put_contents($savePath, json_encode(array_values($filtered), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Step 5: Return success response
    return response()->json([
        'message' => 'Filtered products saved successfully.',
        'total_submitted' => $totalSubmitted,
        'total_matched' => count($filtered)
    ]);
}



}
