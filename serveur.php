
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

// Vérifie que l'utilisateur connecté est admin, sinon renvoie une erreur et arrête
function requireAdmin() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(["status" => "error", "message" => "Accès refusé : réservé aux administrateurs."]);
        exit;
    }
}

try {
switch ($action) {
    // Vérifie si l'utilisateur connecté est admin (pour protéger admin.html côté client)
    case 'is_admin':
        echo json_encode([
            "status" => "success",
            "isAdmin" => isset($_SESSION['role']) && $_SESSION['role'] === 'admin'
        ]);
        break;

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
 
    // Action pour l'admin : Valider (ou rejeter) le compte d'un enseignant
    case 'approver_enseignant':
        requireAdmin();
        if (!isset($data['teacher_id']) || !isset($data['decision'])) {
            echo json_encode(["status" => "error", "message" => "Paramètres manquants."]);
            break;
        }
        $isActiveValue = intval($data['decision']) ? 1 : 0;
        $req = $bdd->prepare('UPDATE users SET isActive = ? WHERE id = ? AND role = "teacher"');
        $req->execute([$isActiveValue, $data['teacher_id']]);
        $msg = $isActiveValue
            ? "Le compte de l'enseignant a été activé."
            : "Le compte de l'enseignant a été désactivé.";
        echo json_encode(["status" => "success", "message" => $msg]);
        break;

    // Liste des enseignants en attente d'activation
    case 'get_pending_teachers':
        requireAdmin();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email FROM users WHERE role = 'teacher' AND isActive = 0");
        $req->execute();
        echo json_encode(["status" => "success", "teachers" => $req->fetchAll()]);
        break;

    // Liste de tous les enseignants (actifs et inactifs)
    case 'get_teachers':
        requireAdmin();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email, isActive FROM users WHERE role = 'teacher'");
        $req->execute();
        echo json_encode(["status" => "success", "teachers" => $req->fetchAll()]);
        break;

    // Promouvoir un utilisateur existant au rôle admin (par email)
    case 'promote_admin':
        requireAdmin();
        if (empty($data['email'])) {
            echo json_encode(["status" => "error", "message" => "Email manquant."]);
            break;
        }
        $req = $bdd->prepare("UPDATE users SET role = 'admin', isActive = 1 WHERE email = ?");
        $req->execute([$data['email']]);
        if ($req->rowCount() === 0) {
            echo json_encode(["status" => "error", "message" => "Aucun utilisateur trouvé avec cet email."]);
        } else {
            echo json_encode(["status" => "success", "message" => "Utilisateur promu administrateur."]);
        }
        break;

    // Liste des administrateurs
    case 'get_admins':
        requireAdmin();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email FROM users WHERE role = 'admin'");
        $req->execute();
        echo json_encode(["status" => "success", "admins" => $req->fetchAll()]);
        break;

    // Liste des cours existants
    case 'get_courses':
        requireAdmin();
        $req = $bdd->prepare("SELECT id, title, code, teacherId FROM courses ORDER BY id DESC");
        $req->execute();
        echo json_encode(["status" => "success", "courses" => $req->fetchAll()]);
        break;

    // Ajouter une leçon (PDF ou vidéo) à un cours
    case 'add_lesson':
        requireAdmin();
        if (empty($data['course_id']) || empty($data['title']) || empty($data['content_type']) || empty($data['file_path'])) {
            echo json_encode(["status" => "error", "message" => "Champs manquants."]);
            break;
        }
        if (!in_array($data['content_type'], ['pdf', 'video'])) {
            echo json_encode(["status" => "error", "message" => "content_type doit être 'pdf' ou 'video'."]);
            break;
        }
        $req = $bdd->prepare('INSERT INTO lessons (course_id, title, content_type, file_path) VALUES (?, ?, ?, ?)');
        $req->execute([$data['course_id'], $data['title'], $data['content_type'], $data['file_path']]);
        echo json_encode(["status" => "success", "message" => "Leçon ajoutée avec succès."]);
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
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Erreur base de données.",
        "debug" => $e->getMessage()
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Erreur serveur.",
        "debug" => $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine()
    ]);
}
?>
