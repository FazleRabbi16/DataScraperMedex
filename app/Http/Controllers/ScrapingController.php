<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScrapingController extends Controller
{
    public function scrapeFromFile()
    {
        set_time_limit(0);  // Prevent script timeout
        $filePath = 'url.txt';
        $outputPath = 'scrabe.txt';
    
        if (!Storage::exists($filePath)) {
            return response()->json(['success' => false, 'message' => 'URL file not found.'], 404);
        }
    
        $urls = explode("\n", trim(Storage::get($filePath)));
        $results = [];
    
        if (Storage::exists($outputPath)) {
            $existingData = json_decode(Storage::get($outputPath), true);
            if (is_array($existingData)) {
                $results = $existingData;
            }
        }
    
        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url)) continue;
    
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
    
            // Extract raw product name (without <small> tag content)
            $rawProductName = $this->getXPathValue($xpath, '//h1[contains(@class, "brand")]');
            // Remove any <small> tag content inside the product name
            $withoutSmall = preg_replace('/<small.*?<\/small>/is', '', $rawProductName);
            // Clean the remaining HTML tags and extract the product name
            $productName = trim(strip_tags($withoutSmall));
    
            // Extract type (dosage form) which is inside the <small> tag
            $type = $this->getXPathValue($xpath, '//h1[contains(@class, "brand")]/small');
    
            // Make sure to extract only the product name (without dosage form) by removing the type part from productName
            // Remove the dosage form (IV Injection, Tablet, etc.) if it exists in the productName.
            if (strpos($productName, $type) !== false) {
                $productName = trim(str_replace($type, '', $productName)); // Remove type from productName
            }
    
            // Extract other details
            $genericName = $this->getXPathValue($xpath, '//div[@title="Generic Name"]/a');
            $strength = $this->getXPathValue($xpath, '//div[@title="Strength"]');
            $companyName = $this->getXPathValue($xpath, '//div[@title="Manufactured by"]/a');
    
            // Extract the unit price and other related info
            $unitPriceRaw = $this->getXPathValue($xpath, '//span[contains(text(), "Unit Price:")]/following-sibling::span[1]');
            $altDoseText = $this->getXPathValue($xpath, '//div[contains(@class, "package-container")]/span[1]');
    
            // If unit price isn't found, fallback to another node
            if ($unitPriceRaw === 'N/A') {
                $unitPriceRaw = $this->getXPathValue($xpath, '//div[contains(@class, "package-container")]/span[2]');
            }
    
            $cleanUnitPrice = (float) str_replace(['৳', 'Tk', ' '], '', $unitPriceRaw);
            $retailMaxPrice = $cleanUnitPrice > 0 ? $cleanUnitPrice : 0.00;
    
            $cartInc = 1; // default
            $packSizeNodes = $xpath->query('//span[contains(@class, "pack-size-info")]');
    
            if ($packSizeNodes->length > 0) {
                $firstPackText = trim($packSizeNodes->item(0)->textContent);
                if (preg_match('/x\s*(\d+)/i', $firstPackText, $matches)) {
                    $cartInc = (int) $matches[1];
                }
            }
    
            // Clean up the type for unit in pack and cart text
            $unitPriceExists = strpos($html, 'Unit Price:') !== false;
            $cartText = str_replace(':', '', $unitPriceExists ? $type : 'x ' . trim($altDoseText));
    
            // Determine the unit in pack information based on dosage form
            if (in_array(strtolower($type), ['tablet', 'capsule'])) {
                $unitInAPack = $cartInc . ' ' . strtolower($cartText) . ' in a strip';
            } else {
                $unitInAPack = strtolower(str_replace('x ', '', $cartText));
            }
    
            // Extract product name from URL to create cover image name
            $urlParts = explode('/', $url);
            $productNameFromURL = end($urlParts);
            $coverImage = $productNameFromURL . '.jpg';
    
            // Collect all the extracted information into an array
            $results[] = [
                'url' => $url,
                'productName' => $productName,  // Cleaned product name without dosage form
                'type' => $type,                // Extracted type (e.g., IV Injection)
                'genericName' => trim($genericName),
                'quantity' => trim($strength),
                'companyName' => trim($companyName),
                'retail_max_price' => number_format($retailMaxPrice, 2, '.', ''),
                'cart_qty_inc' => $cartInc,
                'cart_text' => $cartText,
                'unit_in_pack' => $unitInAPack ?: 'n/a',
                'prescription' => 'yes',
                'feature' => 'no',
                'status' => 'active',
                'coverImage' => $coverImage
            ];
    
            // Write progress after each URL
            Storage::put($outputPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
            // Anti-blocking pause
            $this->introduceDelay();
        }
    
        return response()->json(['success' => true, 'message' => 'Scraping completed and saved to scrabe.txt.']);
    }
    


    // Random delay between requests
    private function introduceDelay()
    {
        sleep(rand(1, 3));
    }

    // Rotate user agents
    private function getRandomUserAgent()
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.114 Safari/537.36',
            'Mozilla/5.0 (Linux; Android 10; Pixel 3 XL) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36',
        ];
        return $userAgents[array_rand($userAgents)];
    }

    // Extract text value by XPath
    private function getXPathValue($xpath, $query)
    {
        $nodes = $xpath->query($query);
        return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->textContent) : 'N/A';
    }
}
