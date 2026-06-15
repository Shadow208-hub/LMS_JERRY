
<?php
// Empêche PHP d'imprimer du HTML d'erreur (notices/warnings/fatal) qui casserait le JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);
 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
 
// Répondre immédiatement aux requêtes preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
 
// Capture toute erreur fatale et la renvoie en JSON propre au lieu de HTML
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=UTF-8");
        }
        echo json_encode([
            "status" => "error",
            "message" => "Erreur serveur interne.",
            "debug" => $error['message'] . " in " . $error['file'] . ":" . $error['line']
        ]);
    }
});
 
session_start();
 
// Connexion à la base MySQL hébergée sur Clever Cloud, via variables d'environnement Render
$host     = getenv('DB_HOST');
$db_name  = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');
$port     = getenv('DB_PORT') ?: 3306;
 
if (!$host || !$db_name || !$username) {
    echo json_encode(["status" => "error", "message" => "Variables de connexion à la base de données manquantes."]);
    exit;
}
 
try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4";
 
    $bdd = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Échec de connexion à la base de données.", "debug" => $e->getMessage()]);
    exit;
}
 
$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true);
 
switch ($action) {
    case 'register':
        // 1. Vérification si l'email existe déjà
        $check = $bdd->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$data['email']]);
        if ($check->fetch()) {
            echo json_encode(["status" => "error", "message" => "Cet email est déjà utilisé."]);
            break;
        }
 
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
    
        // RÈGLE : L'étudiant est actif (1/true), le professeur est inactif par défaut (0/false)
        $isActive = ($data['role'] === 'teacher') ? 0 : 1;
 
        // Insertion avec la colonne isActive
        $req = $bdd->prepare('INSERT INTO users (firstName, lastName, email, passwordHash, role, isActive) VALUES (?, ?, ?, ?, ?, ?)');
        $req->execute([$data['prenom'], $data['nom'], $data['email'], $hashedPassword, $data['role'], $isActive]);
    
        $msg = ($data['role'] === 'teacher') 
            ? "Inscription enregistrée. Votre compte est en attente d'activation par l'administrateur." 
            : "Compte étudiant créé avec succès !";
 
        echo json_encode(["status" => "success", "message" => $msg]);
    break;
 
    case 'login':
        $req = $bdd->prepare('SELECT * FROM users WHERE email = ?');
        $req->execute([$data['email']]);
        $user = $req->fetch();
 
        if ($user && password_verify($data['password'], $user['passwordHash'])) {
        
            // RÈGLE DE SÉCURITÉ : Si le compte n'est pas actif (isActive == 0)
            if ((int)$user['isActive'] === 0) {
                echo json_encode(["status" => "error", "message" => "Votre compte n'a pas encore été validé par l'administrateur."]);
                break;
             }
 
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
        
            echo json_encode([
            "status" => "success",
            "user" => [
                "id" => $user['id'], "firstName" => $user['firstName'], "lastName" => $user['lastName'], "role" => $user['role']
            ]
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Email ou mot de passe incorrect."]);
        }
    break;
 
    // Action pour le Promoteur : Valider le dossier d'un enseignant
    case 'approver_enseignant':
        if (!isset($data['teacher_id']) || !isset($data['decision'])) break;
        $req = $bdd->prepare('UPDATE users SET status_approbation = ? WHERE id = ? AND role = "teacher"');
        $req->execute([$data['decision'], $data['teacher_id']]);
        echo json_encode(["status" => "success", "message" => "Le statut de l'enseignant a été mis à jour (Etude de dossier)."]);
        break;
 
    case 'create_course':
        $req = $bdd->prepare('INSERT INTO courses (title, code, description, teacherId, maxStudents, module_id) VALUES (?, ?, ?, ?, ?, ?)');
        $req->execute([$data['titre'], $data['code'], $data['description'] ?? '', $data['teacherid'], $data['maxetudiant'], $data['module_id'] ?? null]);
        echo json_encode(["status" => "success", "message" => "Le cours a été ajouté et lié avec succès."]);
        break;
 
    case 'submit_evaluation':
        $score = intval($data['score_obtained']);
        $maxScore = intval($data['max_score'] ?? 20);
        $progressPercent = ($score / $maxScore) * 100;
 
        $req = $bdd->prepare('INSERT INTO progress (student_id, lesson_id, score_obtained, progress_percent) VALUES (?, ?, ?, ?)');
        $req->execute([$data['student_id'], $data['lesson_id'], $score, $progressPercent]);
        
        echo json_encode(["status" => "success", "progress_percent" => $progressPercent]);
        break;
 
    // Récupérer les leçons d'un cours (avec le lien de téléchargement PDF)
    case 'get_lessons':
        $req = $bdd->prepare('SELECT * FROM lessons WHERE course_id = ?');
        $req->execute([$_GET['course_id']]);
        echo json_encode($req->fetchAll());
        break;
 
    default:
        echo json_encode(["status" => "error", "message" => "Action non reconnue."]);
        break;
}
?>
