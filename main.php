<?php
// Set the content type to JSON for all responses
header("Content-Type: application/json");

// Add CORS headers for cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    echo json_encode([
        "status" => "success",
        "message" => "CORS preflight request successful"
    ]);
    exit;
}

// FIX pour serveur - getallheaders() n'existe pas toujours
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

define('RENTLIO_API_URL', "https://api.rentl.io/v1");
define('API_KEY', 'RReV6LTX31ZnpWyoHwnXwghULPuyaqLw');

$users = [
    "admin" => "secret"
];

function makeapicall(string $url) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: " . API_KEY,
        ]);
        
        // FIX SSL pour serveur local/développement
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception("Error connecting to Rentlio API: " . curl_error($ch));
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            http_response_code($http_code);
            echo json_encode([
                "status" => "error",
                "message" => "Error from Rentlio API",
                "details" => $response
            ]);
            exit;
        }

        return json_decode($response, true);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage(),
        ]);
        exit;
    }
}

// NOUVELLE FONCTION - Solution exacte comme Rentlio
function getReservationsLikeRentlio($start_date, $end_date) {
    // Format des dates comme Rentlio (DD.MM.YYYY)
    $formatted_start = date("d.m.Y", strtotime($start_date));
    $formatted_end = date("d.m.Y", strtotime($end_date));
    
    // Récupérer d'abord toutes les propriétés
    $properties = makeapicall(RENTLIO_API_URL . "/properties?perPage=100");
    
    $all_reservations = [];
    
    // Pour chaque propriété, utiliser l'endpoint exact de Rentlio
    foreach ($properties["data"] as $property) {
        $property_id = $property["id"];
        
        // URL exacte comme Rentlio
        $url = RENTLIO_API_URL . "/reservations/list?perPage=100&page=1&propertiesId=" . $property_id . "&dateFrom=" . $formatted_start . "&dateTo=" . $formatted_end . "&sortBy=default&sortOrder=desc";
        
        try {
            $response = makeapicall($url);
            
            // Si il y a des données, les ajouter
            if (isset($response["data"]) && is_array($response["data"])) {
                $all_reservations = array_merge($all_reservations, $response["data"]);
            }
            
            // Gérer la pagination si nécessaire
            if (isset($response["last_page"]) && $response["last_page"] > 1) {
                for ($page = 2; $page <= $response["last_page"]; $page++) {
                    $paginated_url = RENTLIO_API_URL . "/reservations/list?perPage=100&page=" . $page . "&propertiesId=" . $property_id . "&dateFrom=" . $formatted_start . "&dateTo=" . $formatted_end . "&sortBy=default&sortOrder=desc";
                    
                    $paginated_response = makeapicall($paginated_url);
                    if (isset($paginated_response["data"]) && is_array($paginated_response["data"])) {
                        $all_reservations = array_merge($all_reservations, $paginated_response["data"]);
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Error fetching reservations for property {$property_id}: " . $e->getMessage());
        }
    }
    
    return $all_reservations;
}

// ANCIENNE FONCTION - Gardée pour compatibilité avec l'historique
function getAllPropertiesReservationsForPeriod($date_from, $date_to) {
    $all_reservations = [];
    $current_page = 1;
    $has_more_pages = true;
    $max_pages = 20;
    
    while ($has_more_pages && $current_page <= $max_pages) {
        $url = RENTLIO_API_URL . "/reservations?page={$current_page}&order_by=createdAt&order_direction=DESC";
        
        try {
            $response = makeapicall($url);
            
            if (isset($response["data"]) && count($response["data"]) > 0) {
                $all_reservations = array_merge($all_reservations, $response["data"]);
                
                if (count($response["data"]) < 30) {
                    $has_more_pages = false;
                }
            } else {
                $has_more_pages = false;
            }
            
            if (isset($response["total"]) && $response["total"] > 0) {
                $estimated_pages = ceil($response["total"] / 30);
                if ($current_page >= $estimated_pages) {
                    $has_more_pages = false;
                }
            }
            
            if (isset($response["current_page"]) && isset($response["last_page"])) {
                $has_more_pages = $response["current_page"] < $response["last_page"];
            }
            
            $current_page++;
            
        } catch (Exception $e) {
            error_log("Error fetching reservations page {$current_page}: " . $e->getMessage());
            break;
        }
    }
    
    return $all_reservations;
}

function getinvoices() {
    $propertyid = [];
    $properties = makeapicall(RENTLIO_API_URL . "/properties?perPage=100");
    foreach ($properties["data"] as $property) {
        $propertyid[] = $property["id"];
    }

    try {
        $ch = curl_init();
        $url = RENTLIO_API_URL . "/invoices?propertiesIds=" . implode(",", $propertyid);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: " . API_KEY,
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception("Error connecting to Rentlio API: " . curl_error($ch));
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            http_response_code($http_code);
            echo json_encode([
                "status" => "error",
                "message" => "Error from Rentlio API",
                "details" => $response
            ]);
            exit;
        }

        return json_decode($response, true);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage(),
        ]);
        exit;
    }
}

// Function to generate a basic token
function generateToken($username) {
    $payload = [
        "username" => $username,
        "issued_at" => time(),
        "expires_at" => time() + (60 * 60) // Token expires in 1 hour
    ];
    return base64_encode(json_encode($payload));
}

// Function to validate a token
function validateToken($token) {
    $decoded = json_decode(base64_decode($token), true);
    if (!$decoded || !isset($decoded['username'], $decoded['expires_at'])) {
        return false;
    }
    if ($decoded['expires_at'] < time()) {
        return false; // Token has expired
    }
    return $decoded; // Return decoded payload if valid
}

// Handle multiple routing methods for maximum compatibility
$cleaned_request = '';

// Method 1: Query parameter (for Google Drive and simple hosting)
if (isset($_GET['endpoint'])) {
    $cleaned_request = $_GET['endpoint'];
}
// Method 2: PATH_INFO (for Apache servers)
elseif (isset($_SERVER['PATH_INFO'])) {
    $cleaned_request = trim($_SERVER['PATH_INFO'], '/');
}
// Method 3: URI parsing (original method)
else {
    $request = $_SERVER['REQUEST_URI'];
    $script_name = $_SERVER['SCRIPT_NAME'];
    $cleaned_request = preg_replace("#^" . preg_quote($script_name, '#') . "#", '', $request);
    $cleaned_request = trim($cleaned_request, '/');
    
    // Parse query parameters
    if (strpos($cleaned_request, '?') !== false) {
        $parts = explode('?', $cleaned_request);
        $cleaned_request = $parts[0];
    }
}

$cleaned_request = trim($cleaned_request, '/');

// Define the endpoints
if ($cleaned_request === 'login') {
    // /login endpoint
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? null;
        $password = $input['password'] ?? null;

        if ($username && $password && isset($users[$username]) && $users[$username] === $password) {
            $token = generateToken($username);
            $response = [
                "status" => "success",
                "message" => "Login successful",
                "token" => $token
            ];
        } else {
            http_response_code(401);
            $response = [
                "status" => "error",
                "message" => "Invalid username or password"
            ];
        }
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only POST requests are allowed on /login"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request === '' || $cleaned_request === 'main.php') {
    // / endpoint
    $response = [
        "status" => "success",
        "message" => "Welcome to the Property Management API - Rentlio Integration",
        "version" => "2.0",
        "endpoints" => [
            "login", "properties", "reservations", "reservations/today", 
            "reservations/booked", "reports/current", "reports/lastmonth", 
            "test/rentlio-method", "invoices"
        ]
    ];
    echo json_encode($response);
}
elseif (strpos($cleaned_request, 'reservations/today') === 0) {
    // /reservations/today endpoint - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $url = RENTLIO_API_URL . "/reservations?dateFrom=" . date("Y-m-d") . "&dateTo=" . date("Y-m-d");
        $response = [
            "status" => "success",
            "message" => "reservations for today retrieved successfully",
            "reservations" => makeapicall($url)
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /reservations/today"
        ];
    }
    echo json_encode($response);
}
elseif (strpos($cleaned_request, 'reservations/booked') === 0) {
    // /reservations/booked endpoint - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $url = RENTLIO_API_URL . "/reservations?bookedAtFrom=" . date("Y-m-d") . "&bookedAtTo=" . date("Y-m-d", strtotime("+1 day"));
        $response = [
            "status" => "success",
            "message" => "reservations booked today retrieved successfully",
            "URL used" => $url,
            "reservations" => makeapicall($url)
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /reservations/booked"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request === 'reservations/list') {
    // /reservations/list endpoint - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Récupérer tous les paramètres
        $per_page = $_GET['perPage'] ?? 30;
        $page = $_GET['page'] ?? 1;
        $properties_id = $_GET['propertiesId'] ?? null;
        $date_from = $_GET['dateFrom'] ?? null;
        $date_to = $_GET['dateTo'] ?? null;
        $sort_by = $_GET['sortBy'] ?? 'default';
        $sort_order = $_GET['sortOrder'] ?? 'desc';
        
        // Construire l'URL pour Rentlio
        $url = RENTLIO_API_URL . "/reservations/list?perPage=" . $per_page . "&page=" . $page;
        
        if ($properties_id) $url .= "&propertiesId=" . $properties_id;
        if ($date_from) $url .= "&dateFrom=" . $date_from;
        if ($date_to) $url .= "&dateTo=" . $date_to;
        $url .= "&sortBy=" . $sort_by . "&sortOrder=" . $sort_order;
        
        $response = [
            "status" => "success",
            "message" => "Reservations list retrieved successfully",
            "url_used" => $url,
            "reservations" => makeapicall($url)
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /reservations/list"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request === 'reservations') {
    // /reservations endpoint - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        
        // Utiliser exactement la même logique que votre Vue.js pour l'historique
        $all_reservations = [];
        $current_page = 1;
        $has_more_pages = true;
        $max_pages = 20;
        
        while ($has_more_pages && $current_page <= $max_pages) {
            $url = RENTLIO_API_URL . "/reservations?page={$current_page}&order_by=createdAt&order_direction=DESC";
            
            try {
                $response = makeapicall($url);
                
                if (isset($response["data"]) && count($response["data"]) > 0) {
                    $all_reservations = array_merge($all_reservations, $response["data"]);
                    
                    if (count($response["data"]) < 30) {
                        $has_more_pages = false;
                    }
                } else {
                    $has_more_pages = false;
                }
                
                if (isset($response["total"]) && $response["total"] > 0) {
                    $estimated_pages = ceil($response["total"] / 30);
                    if ($current_page >= $estimated_pages) {
                        $has_more_pages = false;
                    }
                }
                
                if (isset($response["current_page"]) && isset($response["last_page"])) {
                    $has_more_pages = $response["current_page"] < $response["last_page"];
                }
                
                $current_page++;
                
            } catch (Exception $e) {
                error_log("Error fetching reservations page {$current_page}: " . $e->getMessage());
                break;
            }
        }
        
        // Trier par date de création (plus récent en premier)
        usort($all_reservations, function($a, $b) {
            return ($b['createdAt'] ?? 0) - ($a['createdAt'] ?? 0);
        });
        
        // Pagination manuelle
        $per_page = 30;
        $total = count($all_reservations);
        $offset = ($page - 1) * $per_page;
        $paginated_reservations = array_slice($all_reservations, $offset, $per_page);
        
        // Format de réponse identique à l'original
        $response = [
            "status" => "success",
            "message" => "reservations retrieved successfully",
            "reservations" => [
                "data" => $paginated_reservations,
                "total" => $total,
                "current_page" => $page,
                "last_page" => ceil($total / $per_page),
                "per_page" => $per_page
            ]
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /reservations"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request === 'invoices') {
    // /invoices endpoint - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $response = [
            "status" => "success",
            "message" => "invoices retrieved successfully",
            "invoices" => getinvoices()
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /invoices"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request === 'properties') {
    // /properties endpoint - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $url = RENTLIO_API_URL . "/properties?perPage=100";
        $response = [
            "status" => "success",
            "message" => "properties retrieved successfully",
            "properties" => makeapicall($url)
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /properties"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request == 'reports/current') {
    // /reports/current endpoint - SOLUTION RENTLIO - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Dates du mois en cours
        $start_date = date("Y-m-d", strtotime("first day of this month"));
        $end_date = date("Y-m-d", strtotime("last day of this month"));
        
        // Utiliser la méthode exacte de Rentlio
        $reservations = getReservationsLikeRentlio($start_date, $end_date);
        
        $response = [
            "status" => "success",
            "message" => "current reports retrieved successfully (Rentlio method)",
            "debug" => [
                "total_reservations" => count($reservations),
                "period" => "$start_date to $end_date",
                "method" => "rentlio_exact"
            ],
            "reservations" => $reservations
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /reports/current"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request == 'reports/lastmonth') {
    // /reports/lastmonth endpoint - SOLUTION RENTLIO - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Dates du mois dernier
        $start_date = date("Y-m-d", strtotime("first day of last month"));
        $end_date = date("Y-m-d", strtotime("last day of last month"));
        
        // Utiliser la méthode exacte de Rentlio
        $reservations = getReservationsLikeRentlio($start_date, $end_date);
        
        $response = [
            "status" => "success",
            "message" => "last month reports retrieved successfully (Rentlio method)",
            "debug" => [
                "total_reservations" => count($reservations),
                "period" => "$start_date to $end_date"
            ],
            "reservations" => $reservations
        ];
    } else {
        http_response_code(405);
        $response = [
            "status" => "error",
            "message" => "Only GET requests are allowed on /reports/lastmonth"
        ];
    }
    echo json_encode($response);
}
elseif ($cleaned_request == 'test/rentlio-method') {
    // Endpoint de test pour vérifier la méthode Rentlio - AUTH DÉSACTIVÉE
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Test pour juillet 2025 comme dans votre exemple
        $reservations = getReservationsLikeRentlio("2025-07-01", "2025-07-31");
        
        $naia_reservations = array_filter($reservations, function($r) {
            return stripos($r['unitName'] ?? '', 'naia') !== false;
        });
        
        $response = [
            "status" => "success",
            "message" => "Test Rentlio method for July 2025",
            "debug" => [
                "total_all_properties" => count($reservations),
                "naia_only" => count($naia_reservations),
                "naia_reservations" => array_values($naia_reservations)
            ]
        ];
    }
    echo json_encode($response);
}
else {
    // Handle unknown endpoints
    http_response_code(404);
    $response = [
        "status" => "error",
        "message" => "Endpoint not found",
        "requested_endpoint" => $cleaned_request,
        "available_endpoints" => [
            "login", "properties", "reservations", "reservations/today", 
            "reservations/booked", "reports/current", "reports/lastmonth", 
            "test/rentlio-method", "invoices"
        ]
    ];
    echo json_encode($response);
}
?>
