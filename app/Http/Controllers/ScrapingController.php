<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RequestProduct;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Validator;
use Auth;

class ScrapingController extends Controller
{
    public function scrapeMultiple(Request $request)
{
    $validator = Validator::make($request->all(), [
        'urls' => 'required|array',
        'urls.*' => 'required|url',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()]);
    }

    $urls = $request->input('urls');
    $results = [];

    foreach ($urls as $url) {
        // Randomize User-Agent and set it in the context options
        $userAgent = $this->getRandomUserAgent();

        $contextOptions = [
            "http" => [
                "header" => "User-Agent: $userAgent"
            ]
        ];

        $context = stream_context_create($contextOptions);
        $html = @file_get_contents($url, false, $context);

        if ($html === false) {
            continue;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        // Main data
        $rawProductName = $this->getXPathValue($xpath, '//h1[contains(@class, "brand")]');
        $productName = trim(preg_replace('/<[^>]*>/', '', $rawProductName)); // Remove <small> etc.

        $dosageForm = $this->getXPathValue($xpath, '//h1[contains(@class, "brand")]/small');
        $genericName = $this->getXPathValue($xpath, '//div[@title="Generic Name"]/a');
        $strength = $this->getXPathValue($xpath, '//div[@title="Strength"]');
        $manufacturer = $this->getXPathValue($xpath, '//div[@title="Manufactured by"]/a');

        // Prices
        $unitPriceRaw = $this->getXPathValue($xpath, '//span[contains(text(), "Unit Price:")]/following-sibling::span[1]');
        $stripPriceRaw = $this->getXPathValue($xpath, '//span[contains(text(), "Strip Price:")]/following-sibling::span[1]');
        $altDoseText = $this->getXPathValue($xpath, '//div[contains(@class, "package-container")]/span[1]');

        // Fallback logic if unit price isn't found
        if ($unitPriceRaw === 'N/A') {
            $unitPriceRaw = $this->getXPathValue($xpath, '//div[contains(@class, "package-container")]/span[2]');
        }

        // Clean price values
        $cleanUnitPrice = (float) str_replace(['৳', 'Tk', ' '], '', $unitPriceRaw);
        $cleanStripPrice = (float) str_replace(['৳', 'Tk', ' '], '', $stripPriceRaw);

        $retailMaxPrice = $cleanUnitPrice > 0 ? $cleanUnitPrice : 0.00;
        $cartInc = ($cleanStripPrice > 0 && $cleanUnitPrice > 0) ? round($cleanStripPrice / $cleanUnitPrice) : 1;

        $type = trim(preg_replace('/\s*\(.*?\)/', '', $dosageForm)); // e.g., "Tablet"

        // Determine cart_text and unit_in_pack
        $unitPriceExists = strpos($html, 'Unit Price:') !== false;
        $cartText = str_replace(':', '', $unitPriceExists ? $type : 'x ' . trim($altDoseText));

        $unitInAPack = '';
        if (in_array(strtolower($type), ['tablet', 'capsule'])) {
            $unitInAPack = $cartInc . ' ' . strtolower($cartText) . ' in a strip';
        } else {
            $unitInAPack = strtolower(str_replace('x ', '', $cartText));
        }

        // Cover image
        $urlParts = explode('/', $url);
        $productNameFromURL = end($urlParts);
        $coverImage = $productNameFromURL . '.jpg';

        // Get company info
        // $company = Company::where('name', $manufacturer)->first();
        $companyId = 1;
        $companyName = "ABC";

        $results[] = (object)[
            'url' => $url,
            'productName' => trim($productName),
            'type' => $type ?: 'N/A',
            'genericName' => trim($genericName),
            'quantity' => trim($strength),
            'company_id' => $companyId,
            'companyName' => $companyName,
            'retail_max_price' => number_format($retailMaxPrice, 2, '.', ''),
            'cart_qty_inc' => $cartInc,
            'cart_text' => $cartText,
            'unit_in_pack' => $unitInAPack ?: 'n/a',
            'prescription' => 'yes',
            'feature' => 'no',
            'status' => 'active',
            'coverImage' => $coverImage
        ];

        // Add delay between requests to avoid rate-limiting
        $this->introduceDelay();
    }

    return response()->json($results);
}

// Helper function to introduce random delay between requests
private function introduceDelay()
{
    $min = 1; // Minimum delay (in seconds)
    $max = 3; // Maximum delay (in seconds)
    $delay = rand($min, $max);
    sleep($delay);  // Introduce the delay
}

// Helper function to get random User-Agent from a list
private function getRandomUserAgent()
{
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.114 Safari/537.36',
        'Mozilla/5.0 (Linux; Android 10; Pixel 3 XL) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36',
        // Add more User-Agents as needed
    ];

    return $userAgents[array_rand($userAgents)];
}

// Helper function to get XPath value
private function getXPathValue($xpath, $query)
{
    $nodes = $xpath->query($query);
    if ($nodes && $nodes->length > 0) {
        return trim($nodes->item(0)->textContent);
    }
    return 'N/A';
}
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

}
