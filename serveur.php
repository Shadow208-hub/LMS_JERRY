
<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
 
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
 
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(["status" => "error", "message" => "Erreur serveur interne.", "debug" => $error['message']]);
    }
});
 
// ----------------------------------------------------------------
// TOKEN : mini-JWT maison (header.payload.signature en base64url)
// ----------------------------------------------------------------
define('TOKEN_SECRET', getenv('TOKEN_SECRET') ?: 'lms_secret_key_change_en_prod');
define('TOKEN_TTL', 60 * 60 * 8); // 8 heures
 
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}
 
function creerToken(int $userId, string $role): string {
    $payload = base64url_encode(json_encode(['uid' => $userId, 'role' => $role, 'exp' => time() + TOKEN_TTL]));
    $header  = base64url_encode(json_encode(['alg' => 'HS256']));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$payload", TOKEN_SECRET, true));
    return "$header.$payload.$sig";
}
 
function verifierToken(): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$auth || !str_starts_with($auth, 'Bearer ')) return null;
    $token = substr($auth, 7);
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expectedSig = base64url_encode(hash_hmac('sha256', "$header.$payload", TOKEN_SECRET, true));
    if (!hash_equals($expectedSig, $sig)) return null;
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || $data['exp'] < time()) return null;
    return $data; // ['uid' => ..., 'role' => ...]
}
 
function requireAdmin(): array {
    $token = verifierToken();
    if (!$token || $token['role'] !== 'admin') {
        echo json_encode(["status" => "error", "message" => "Accès refusé : réservé aux administrateurs."]);
        exit;
    }
    return $token;
}
 
function requireAuth(): array {
    $token = verifierToken();
    if (!$token) {
        echo json_encode(["status" => "error", "message" => "Non connecté."]);
        exit;
    }
    return $token;
}
 
// ----------------------------------------------------------------
// Base de données
// ----------------------------------------------------------------
$host     = getenv('DB_HOST');
$db_name  = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');
$port     = getenv('DB_PORT') ?: 3306;
 
if (!$host || !$db_name || !$username) {
    echo json_encode(["status" => "error", "message" => "Variables de connexion manquantes."]);
    exit;
}
 
try {
    $bdd = new PDO(
        "mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4",
        $username, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Échec de connexion à la BDD.", "debug" => $e->getMessage()]);
    exit;
}
 
$action = $_GET['action'] ?? '';
$data   = json_decode(file_get_contents('php://input'), true) ?? [];
 
try {
switch ($action) {
 
    // ------------------------------------------------------------------
    case 'is_admin':
        $token = verifierToken();
        echo json_encode([
            "status"  => "success",
            "isAdmin" => $token && $token['role'] === 'admin'
        ]);
        break;
 
    // ------------------------------------------------------------------
    case 'register':
        $check = $bdd->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$data['email']]);
        if ($check->fetch()) {
            echo json_encode(["status" => "error", "message" => "Cet email est déjà utilisé."]);
            break;
        }
        $isActive = ($data['role'] === 'teacher') ? 0 : 1;
        $req = $bdd->prepare('INSERT INTO users (firstName, lastName, email, passwordHash, role, isActive) VALUES (?, ?, ?, ?, ?, ?)');
        $req->execute([$data['prenom'], $data['nom'], $data['email'], password_hash($data['password'], PASSWORD_BCRYPT), $data['role'], $isActive]);
        $msg = ($data['role'] === 'teacher')
            ? "Inscription enregistrée. Votre compte est en attente d'activation."
            : "Compte étudiant créé avec succès !";
        echo json_encode(["status" => "success", "message" => $msg]);
        break;
 
    // ------------------------------------------------------------------
    case 'login':
        $req = $bdd->prepare('SELECT * FROM users WHERE email = ?');
        $req->execute([$data['email']]);
        $user = $req->fetch();
        if ($user && password_verify($data['password'], $user['passwordHash'])) {
            if ((int)$user['isActive'] === 0) {
                echo json_encode(["status" => "error", "message" => "Votre compte n'a pas encore été validé par l'administrateur."]);
                break;
            }
            echo json_encode([
                "status" => "success",
                "token"  => creerToken((int)$user['id'], $user['role']),
                "user"   => ["id" => $user['id'], "firstName" => $user['firstName'], "lastName" => $user['lastName'], "role" => $user['role']]
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Email ou mot de passe incorrect."]);
        }
        break;
 
    // ------------------------------------------------------------------
    case 'create_course':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'admin'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }
        if (empty($data['titre']) || empty($data['code']) || empty($data['teacherid']) || empty($data['maxetudiant'])) {
            echo json_encode(["status" => "error", "message" => "Champs obligatoires manquants."]);
            break;
        }
        $checkCode = $bdd->prepare('SELECT id FROM courses WHERE code = ?');
        $checkCode->execute([strtoupper($data['code'])]);
        if ($checkCode->fetch()) {
            echo json_encode(["status" => "error", "message" => "Ce code de cours existe déjà."]);
            break;
        }
        $req = $bdd->prepare('INSERT INTO courses (title, code, description, teacherId, maxStudents) VALUES (?, ?, ?, ?, ?)');
        $req->execute([$data['titre'], strtoupper($data['code']), $data['description'] ?? '', $data['teacherid'], $data['maxetudiant']]);
        echo json_encode(["status" => "success", "message" => "Cours créé avec succès !", "course_id" => $bdd->lastInsertId()]);
        break;
 
    // ------------------------------------------------------------------
    case 'add_lesson':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'admin'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }
        if (empty($data['course_id']) || empty($data['title']) || empty($data['content_type']) || empty($data['file_path'])) {
            echo json_encode(["status" => "error", "message" => "Champs manquants."]);
            break;
        }
        if (!in_array($data['content_type'], ['pdf', 'video'])) {
            echo json_encode(["status" => "error", "message" => "content_type doit être 'pdf' ou 'video'."]);
            break;
        }
        if ($auth['role'] === 'teacher') {
            $checkOwner = $bdd->prepare('SELECT id FROM courses WHERE id = ? AND teacherId = ?');
            $checkOwner->execute([$data['course_id'], $auth['uid']]);
            if (!$checkOwner->fetch()) {
                echo json_encode(["status" => "error", "message" => "Ce cours ne vous appartient pas."]);
                break;
            }
        }
        $req = $bdd->prepare('INSERT INTO lessons (course_id, title, content_type, file_path) VALUES (?, ?, ?, ?)');
        $req->execute([$data['course_id'], $data['title'], $data['content_type'], $data['file_path']]);
        echo json_encode(["status" => "success", "message" => "Leçon ajoutée avec succès."]);
        break;
 
    // ------------------------------------------------------------------
    case 'get_lessons':
        $req = $bdd->prepare('SELECT * FROM lessons WHERE course_id = ?');
        $req->execute([$_GET['course_id']]);
        echo json_encode(["status" => "success", "lessons" => $req->fetchAll()]);
        break;
 
    // ------------------------------------------------------------------
    case 'get_teacher_courses':
        $auth = requireAuth();
        $tid  = ($auth['role'] === 'admin' && isset($_GET['teacher_id']))
            ? intval($_GET['teacher_id'])
            : $auth['uid'];
        $req = $bdd->prepare("SELECT id, title, code, description, maxStudents FROM courses WHERE teacherId = ? ORDER BY id DESC");
        $req->execute([$tid]);
        echo json_encode(["status" => "success", "courses" => $req->fetchAll()]);
        break;
 
    // ------------------------------------------------------------------
    case 'get_courses':
        $auth = requireAuth();
        if ($auth['role'] === 'admin') {
            $req = $bdd->prepare("SELECT id, title, code, teacherId FROM courses ORDER BY id DESC");
            $req->execute();
        } else {
            $req = $bdd->prepare("SELECT id, title, code, teacherId FROM courses WHERE teacherId = ? ORDER BY id DESC");
            $req->execute([$auth['uid']]);
        }
        echo json_encode(["status" => "success", "courses" => $req->fetchAll()]);
        break;
 
    // ------------------------------------------------------------------
    case 'approver_enseignant':
        requireAdmin();
        if (!isset($data['teacher_id']) || !isset($data['decision'])) {
            echo json_encode(["status" => "error", "message" => "Paramètres manquants."]);
            break;
        }
        $isActiveValue = intval($data['decision']) ? 1 : 0;
        $req = $bdd->prepare('UPDATE users SET isActive = ? WHERE id = ? AND role = "teacher"');
        $req->execute([$isActiveValue, $data['teacher_id']]);
        echo json_encode(["status" => "success", "message" => $isActiveValue ? "Compte activé." : "Compte désactivé."]);
        break;
 
    case 'get_pending_teachers':
        requireAdmin();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email FROM users WHERE role = 'teacher' AND isActive = 0");
        $req->execute();
        echo json_encode(["status" => "success", "teachers" => $req->fetchAll()]);
        break;
 
    case 'get_teachers':
        requireAdmin();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email, isActive FROM users WHERE role = 'teacher'");
        $req->execute();
        echo json_encode(["status" => "success", "teachers" => $req->fetchAll()]);
        break;
 
    case 'promote_admin':
        requireAdmin();
        if (empty($data['email'])) { echo json_encode(["status" => "error", "message" => "Email manquant."]); break; }
        $req = $bdd->prepare("UPDATE users SET role = 'admin', isActive = 1 WHERE email = ?");
        $req->execute([$data['email']]);
        echo json_encode($req->rowCount()
            ? ["status" => "success", "message" => "Utilisateur promu administrateur."]
            : ["status" => "error",   "message" => "Aucun utilisateur trouvé."]);
        break;
 
    case 'get_admins':
        requireAdmin();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email FROM users WHERE role = 'admin'");
        $req->execute();
        echo json_encode(["status" => "success", "admins" => $req->fetchAll()]);
        break;
 
    // ------------------------------------------------------------------
    case 'submit_evaluation':
        $auth = requireAuth();
        $score = intval($data['score_obtained']);
        $maxScore = intval($data['max_score'] ?? 20);
        $req = $bdd->prepare('INSERT INTO progress (student_id, lesson_id, score_obtained, progress_percent) VALUES (?, ?, ?, ?)');
        $req->execute([$data['student_id'], $data['lesson_id'], $score, ($score / $maxScore) * 100]);
        echo json_encode(["status" => "success", "progress_percent" => round(($score / $maxScore) * 100, 1)]);
        break;
 
    default:
        echo json_encode(["status" => "error", "message" => "Action non reconnue."]);
        break;
}
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Erreur base de données.", "debug" => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(["status" => "error", "message" => "Erreur serveur.", "debug" => $e->getMessage()]);
}
?>
