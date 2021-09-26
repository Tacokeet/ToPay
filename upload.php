<?php

require "vendor/autoload.php";

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\TextAnnotation;

//File upload variables
$target_dir = "uploads/";
$target_file = $target_dir . basename($_FILES["fileToUpload"]["name"]);
$imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));


//Check if file type is of an image format
if ($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg") {
    echo "Sorry, only JPG, JPEG and PNG files are allowed.";
    return;
} elseif (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
    echo "The file " . htmlspecialchars(basename($_FILES["fileToUpload"]["name"])) . " has been uploaded.<br>";
} else {
    echo "Sorry, there was an error uploading your file.";
}


//Create an ImageAnnotarClient with Google credentials
try {
    $imageAnnotator = new ImageAnnotatorClient(['credentials' => 'topay-327013-7fa9c85d1e65.json']);
} catch (\Google\ApiCore\ValidationException $e) {
    echo "There was a problem with creating a ImageAnnotatorClient.";
    return;
}

//Get file contents of upload file and then proceed to request textDetection
$image = file_get_contents($target_file);
try {
    $response = $imageAnnotator->textDetection($image);
} catch (\Google\ApiCore\ApiException $e) {
    echo "Could not call the Google Vision Api";
    return;
}
$ocrTexts = $response->getFullTextAnnotation();

/**
 * Function that check if in a given string contains every words of a given array.
 * @param array $keywords
 * @param string $text
 * @return array
 */
function checkKeywords(array $keywords, string $text)
{
    $textContains = [];

    foreach ($keywords as $keyword) {
        if (str_contains($text, $keyword)) {
            array_push($textContains, $keyword);
        }
    }
    return $textContains;
}

/**
 * Function that tries to get invoice values.
 * @param TextAnnotation $textAnnotation
 * @return array
 */
function getInvoiceValues(TextAnnotation $textAnnotation)
{
    //Flags variables to know when words matches string.
    $flagInvoiceNumber = false;
    $flagInvoiceDate = false;
    $flagInvoiceExpireDate = false;
    $flagCustomerNumber = false;
    $flagKvK = false;
    $flagBTW = false;

    //Variables to hold invoiceValues
    $invoiceNumber = "";
    $invoiceDate = "";
    $invoiceExpireDate = "";
    $customerNumber = "";
    $KvK = "";
    $BTW = "";

    //OCR text manipulation lower and array of text
    $totalTextLower = strtolower($textAnnotation->getText());
    $totalTextArray = preg_split('/\s+/', $totalTextLower);


    /**
     * Loop through each page, each block, each paragraph, each word.
     * If word contains a specific keyword set flag true so that we capture the following word.
     * If words needs to have numbers in it it checks on that via preg_match().
     * If it is nummer, nr, of is it wil continue to try to capture the following word.
     */
    foreach ($textAnnotation->getPages() as $page) {
        foreach ($page->GetBlocks() as $block) {
            foreach ($block->getParagraphs() as $paragraph) {
                foreach ($paragraph->getWords() as $word) {
                    $result = "";
                    foreach ($word->getSymbols() as $symbol) {
                        $result .= $symbol->getText();
                    }
                    if ($flagInvoiceNumber) {
                        if (preg_match('~[0-9]~', $result)) {
                            $invoiceNumber = $result;
                        } elseif (str_contains(strtolower($result), "nummer") or str_contains(strtolower($result), "nr") or str_contains(strtolower($result), "is")) {
                            continue;
                        } else {
                            $flagInvoiceNumber = false;
                        }
                    } elseif ($flagInvoiceDate) {
                        if (preg_match('~[0-9]~', $result)) {
                            $invoiceDate = $result;
                        }
                        $flagInvoiceDate = false;
                    } elseif ($flagInvoiceExpireDate) {
                        if (preg_match('~[0-9]~', $result)) {
                            $invoiceExpireDate = $result;
                        }
                        $flagInvoiceExpireDate = false;
                    } elseif ($flagCustomerNumber) {
                        if (preg_match('~[0-9]~', $result)) {
                            $customerNumber = $result;
                        } elseif (str_contains(strtolower($result), "nummer") or str_contains(strtolower($result), "nr") or str_contains(strtolower($result), "is")) {
                            continue;
                        } else {
                            $flagCustomerNumber = false;
                        }
                    } elseif ($flagKvK) {
                        if (preg_match('~[0-9]~', $result)) {
                            $KvK = $result;
                        } elseif (str_contains(strtolower($result), "nummer") or str_contains(strtolower($result), "nr") or str_contains(strtolower($result), "is")) {
                            continue;
                        } else {
                            $flagKvK = false;
                        }
                    } elseif ($flagBTW) {
                        if (preg_match('~[0-9]~', $result) and preg_match('/(B)/', $result)) {
                            $BTW = $result;
                        } elseif (str_contains(strtolower($result), "nummer") or str_contains(strtolower($result), "nr") or str_contains(strtolower($result), "is")) {
                            continue;
                        } else {
                            $flagBTW = false;
                        }
                    }
                    if (str_contains(strtolower($result), "factuurnummer")) {
                        $flagInvoiceNumber = true;
                    } elseif (str_contains(strtolower($result), "kvk")) {
                        $flagKvK = true;
                    } elseif (str_contains(strtolower($result), "btw")) {
                        $flagBTW = true;
                    } elseif (str_contains(strtolower($result), "factuurdatum")) {
                        $flagInvoiceDate = true;
                    } elseif (str_contains(strtolower($result), "verval")) {
                        $flagInvoiceExpireDate = true;
                    } elseif (str_contains(strtolower($result), "klant")) {
                        $flagCustomerNumber = true;
                    }

                }
            }
        }
    }

    $invoiceValues = ['invoiceNumber' => $invoiceNumber, 'invoiceDate' => $invoiceDate, 'invoiceExpireDate' => $invoiceExpireDate, 'customerNumber' => $customerNumber, 'KvK' => $KvK, 'BTW' => $BTW];

    /**
     * If the previous loop could not capture the value this foreach switch statement wil try to.
     * Searches through whole text to find specific mentions of a keyword.
     */
    foreach ($invoiceValues as $key => $value) {
        switch ($key) {
            case 'invoiceNumber':
                if (empty($value)) {
                    foreach ($totalTextArray as $word) {
                        if (str_contains($word, 'factuurnummer')) {
                            $result = array_search($word, $totalTextArray);
                        }
                    }
                    if ($result) {
                        $searchResult = $totalTextArray[$result + 1];
                        if (preg_match('~[0-9]~', $searchResult)) {
                            $invoiceValues['invoiceNumber'] = $searchResult;
                        }
                    }
                }
                break;
            case 'invoiceDate':
                if (empty($value)) {
                    foreach ($totalTextArray as $word) {
                        if (str_contains($word, 'factuurdatum')) {
                            $result = array_search($word, $totalTextArray);
                        }
                    }
                    if ($result) {
                        $searchResult = $totalTextArray[$result + 1];
                        if (preg_match('~[0-9]~', $searchResult)) {
                            $invoiceValues['invoiceDate'] = $searchResult;
                        }
                    }
                }
                break;
            case 'invoiceExpireDate':
                if (empty($value)) {
                    foreach ($totalTextArray as $word) {
                        if (str_contains($word, 'verval')) {
                            $result = array_search($word, $totalTextArray);
                        }
                    }
                    if ($result) {
                        $searchResult = $totalTextArray[$result + 1];
                        if (preg_match('~[0-9]~', $searchResult)) {
                            $invoiceValues['invoiceExpireDate'] = $searchResult;
                        }
                    }
                }
                break;
            case 'customerNumber':
                if (empty($value)) {
                    foreach ($totalTextArray as $word) {
                        if (str_contains($word, 'klant')) {
                            $result = array_search($word, $totalTextArray);
                        }
                    }
                    if ($result) {
                        $searchResult = $totalTextArray[$result + 1];
                        if (preg_match('~[0-9]~', $searchResult)) {
                            $invoiceValues['customerNumber'] = $searchResult;
                        }
                    }
                }
                break;
            case 'Kvk':
                if (empty($value)) {
                    foreach ($totalTextArray as $word) {
                        if (str_contains($word, 'kvk')) {
                            $result = array_search($word, $totalTextArray);
                        }
                    }
                    if ($result) {
                        $searchResult = $totalTextArray[$result + 1];
                        if (preg_match('~[0-9]~', $searchResult)) {
                            $invoiceValues['Kvk'] = $searchResult;
                        }
                    }
                }
                break;
            case 'BTW':
                //This searches though whole text to find specific regex for a BTW number.
                preg_match('/NL[0-9]{9}[B][0-9]{2}/', str_replace('.', '', $textAnnotation->getText()), $matches);
                if (!empty($matches)) {
                    $invoiceValues['BTW'] = $matches[0];
                }
                break;
        }

    }

    return $invoiceValues;
}

$check = checkKeywords(["kvk", "kamer van koophandel", "factuur", "factuurnummer", "factuurdatum", "verval", "klant", "Debiteur", "btw"], strtolower($ocrTexts->getText()));

if (count($check) >= 4) {
    echo "Your photo contains an invoice. <br> It has the following characteristics: ";
    foreach ($check as $word) {
        echo $word . ", ";
    }
    $invoiceValues = getInvoiceValues($ocrTexts);


    echo "<br>Trying to match the characteristics with their value";
    echo "<br></br>InvoiceNumber = " . $invoiceValues['invoiceNumber'] . "<br>InvoiceDate = " . $invoiceValues['invoiceDate'] . "<br>InvoiceExpireDate = " . $invoiceValues['invoiceExpireDate'] . "<br>CustomerNumber = " . $invoiceValues['customerNumber'] . "<br>KvK = " . $invoiceValues['KvK'] . "<br>BTW = " . $invoiceValues['BTW'];
} else {
    echo "Your photo does not contain an invoice, if you think it does upload a different image.";
}
