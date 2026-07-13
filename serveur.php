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
    $auth = $_SERVER['HTTP_AUTHORIZATION']
         ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
         ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '')
         ?? '';
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
 
function requirePromoteur(): array {
    $token = verifierToken();
    if (!$token || $token['role'] !== 'promoteur') {
        echo json_encode(["status" => "error", "message" => "Accès refusé : réservé au promoteur."]);
        exit;
    }
    return $token;
}

// Génère un code de certificat unique, infalsifiable (SHA-256)
function genererCodeCertificat(int $studentId, int $moduleId): string {
    return hash('sha256', $studentId . '|' . $moduleId . '|' . bin2hex(random_bytes(16)) . '|' . time());
}

// Calcule la moyenne de progression d'un étudiant sur un module, et délivre
// automatiquement le certificat si le seuil est atteint et qu'il n'existe pas déjà.
function verifierEtDelivrerCertificat(PDO $bdd, int $studentId, int $moduleId): ?array {
    $reqCourses = $bdd->prepare('SELECT course_id FROM module_courses WHERE module_id = ?');
    $reqCourses->execute([$moduleId]);
    $courseIds = array_column($reqCourses->fetchAll(), 'course_id');
    if (empty($courseIds)) return null;

    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

    $reqLessons = $bdd->prepare("SELECT id FROM lessons WHERE course_id IN ($placeholders)");
    $reqLessons->execute($courseIds);
    $lessonIds = array_column($reqLessons->fetchAll(), 'id');
    if (empty($lessonIds)) return null;

    $placeholdersL = implode(',', array_fill(0, count($lessonIds), '?'));

    $reqDone = $bdd->prepare("SELECT lesson_id, progress_percent FROM progress WHERE student_id = ? AND lesson_id IN ($placeholdersL)");
    $reqDone->execute(array_merge([$studentId], $lessonIds));
    $done = $reqDone->fetchAll();

    if (count($done) < count($lessonIds)) return null; // pas encore terminé toutes les leçons

    $moyenne = array_sum(array_column($done, 'progress_percent')) / count($done);

    $reqSeuil = $bdd->prepare('SELECT seuil_validation FROM modules WHERE id = ?');
    $reqSeuil->execute([$moduleId]);
    $seuil = (int)($reqSeuil->fetchColumn() ?: 60);

    if ($moyenne < $seuil) return null;

    $reqExist = $bdd->prepare('SELECT code FROM certificates WHERE student_id = ? AND module_id = ?');
    $reqExist->execute([$studentId, $moduleId]);
    $existing = $reqExist->fetchColumn();
    if ($existing) return ['code' => $existing, 'average' => round($moyenne, 1), 'already' => true];

    $code = genererCodeCertificat($studentId, $moduleId);
    $reqInsert = $bdd->prepare('INSERT INTO certificates (student_id, module_id, code, average_score) VALUES (?, ?, ?, ?)');
    $reqInsert->execute([$studentId, $moduleId, $code, round($moyenne, 2)]);

    return ['code' => $code, 'average' => round($moyenne, 1), 'already' => false];
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
$host     = getenv('DB_HOST')     ?: 'localhost';
$db_name  = getenv('DB_NAME')     ?: 'lms_local';
$username = getenv('DB_USER')     ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$port     = getenv('DB_PORT')     ?: 3306;
// ↑ Valeurs par défaut pour XAMPP en local (MySQL root sans mot de passe).
//   En production (Render/Clever Cloud), les vraies variables d'environnement
//   DB_HOST / DB_NAME / DB_USER / DB_PASSWORD prennent le dessus automatiquement.
 
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
    case 'is_promoteur':
        $token = verifierToken();
        echo json_encode([
            "status"      => "success",
            "isPromoteur" => $token && $token['role'] === 'promoteur'
        ]);
        break;
 
    // ------------------------------------------------------------------
    case 'register':
        if (!in_array($data['role'] ?? '', ['student', 'teacher'], true)) {
            echo json_encode(["status" => "error", "message" => "Rôle invalide."]);
            break;
        }
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
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
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
        // Le token peut arriver soit en header Authorization, soit dans $_POST['token'] (FormData upload)
        $tokenData = verifierToken();
        if (!$tokenData && !empty($_POST['token'])) {
            // Vérification manuelle depuis le champ FormData
            $tkRaw  = $_POST['token'];
            $parts  = explode('.', $tkRaw);
            if (count($parts) === 3) {
                [$hdr, $pld, $sg] = $parts;
                $expSig = base64url_encode(hash_hmac('sha256', "$hdr.$pld", TOKEN_SECRET, true));
                if (hash_equals($expSig, $sg)) {
                    $decoded = json_decode(base64url_decode($pld), true);
                    if ($decoded && $decoded['exp'] >= time()) $tokenData = $decoded;
                }
            }
        }
        if (!$tokenData) {
            echo json_encode(["status" => "error", "message" => "Non connecté."]);
            break;
        }
        $auth = $tokenData;
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }

        // Récupérer les champs selon le mode (FormData ou JSON)
        $isFormData   = !empty($_POST);
        $courseId     = $isFormData ? intval($_POST['course_id']   ?? 0) : intval($data['course_id']    ?? 0);
        $title        = $isFormData ? trim($_POST['title']         ?? '') : trim($data['title']          ?? '');
        $contentType  = $isFormData ? trim($_POST['content_type']  ?? '') : trim($data['content_type']   ?? '');
        $filePath     = '';

        if (!$courseId || !$title || !$contentType) {
            echo json_encode(["status" => "error", "message" => "Champs manquants."]);
            break;
        }
        if (!in_array($contentType, ['pdf', 'video'])) {
            echo json_encode(["status" => "error", "message" => "content_type doit être 'pdf' ou 'video'."]);
            break;
        }

        // Vérification ownership pour les enseignants
        if ($auth['role'] === 'teacher') {
            $checkOwner = $bdd->prepare('SELECT id FROM courses WHERE id = ? AND teacherId = ?');
            $checkOwner->execute([$courseId, $auth['uid']]);
            if (!$checkOwner->fetch()) {
                echo json_encode(["status" => "error", "message" => "Ce cours ne vous appartient pas."]);
                break;
            }
        }

        if ($contentType === 'pdf') {
            // Upload du fichier PDF
            if (empty($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(["status" => "error", "message" => "Fichier PDF manquant ou erreur d'upload."]);
                break;
            }
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $originalName = basename($_FILES['pdf_file']['name']);
            $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $destination  = $uploadDir . $safeName;

            // Vérifier que c'est bien un PDF (MIME)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['pdf_file']['tmp_name']);
            finfo_close($finfo);
            if ($mime !== 'application/pdf') {
                echo json_encode(["status" => "error", "message" => "Le fichier doit être un PDF."]);
                break;
            }

            if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $destination)) {
                echo json_encode(["status" => "error", "message" => "Échec de l'enregistrement du fichier."]);
                break;
            }
            $filePath = 'uploads/' . $safeName;
        } else {
            // Vidéo : soit un fichier uploadé, soit un lien URL (au choix du prof)
            $videoSource = $isFormData ? trim($_POST['video_source'] ?? 'url') : trim($data['video_source'] ?? 'url');

            if ($videoSource === 'file') {
                if (empty($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(["status" => "error", "message" => "Fichier vidéo manquant ou erreur d'upload."]);
                    break;
                }
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $originalName = basename($_FILES['video_file']['name']);
                $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
                $destination  = $uploadDir . $safeName;

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['video_file']['tmp_name']);
                finfo_close($finfo);
                if (strpos($mime, 'video/') !== 0) {
                    echo json_encode(["status" => "error", "message" => "Le fichier doit être une vidéo (mp4, webm, mov...)."]);
                    break;
                }

                if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $destination)) {
                    echo json_encode(["status" => "error", "message" => "Échec de l'enregistrement du fichier."]);
                    break;
                }
                $filePath = 'uploads/' . $safeName;
            } else {
                $filePath = $isFormData ? trim($_POST['file_path'] ?? '') : trim($data['file_path'] ?? '');
                if (!$filePath) {
                    echo json_encode(["status" => "error", "message" => "URL de la vidéo manquante."]);
                    break;
                }
            }
        }

        $req = $bdd->prepare('INSERT INTO lessons (course_id, title, content_type, file_path) VALUES (?, ?, ?, ?)');
        $req->execute([$courseId, $title, $contentType, $filePath]);
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
        $tid  = ($auth['role'] === 'promoteur' && isset($_GET['teacher_id']))
            ? intval($_GET['teacher_id'])
            : $auth['uid'];
        $req = $bdd->prepare("SELECT id, title, code, description, maxStudents FROM courses WHERE teacherId = ? ORDER BY id DESC");
        $req->execute([$tid]);
        echo json_encode(["status" => "success", "courses" => $req->fetchAll()]);
        break;
 
    // ------------------------------------------------------------------
    case 'get_courses':
        $auth = requireAuth();
        if ($auth['role'] === 'student') {
            // L'étudiant voit tous les cours disponibles
            $req = $bdd->prepare("SELECT id, title, code, description FROM courses ORDER BY id DESC");
            $req->execute();
        } elseif ($auth['role'] === 'promoteur') {
            $req = $bdd->prepare("SELECT id, title, code, teacherId FROM courses ORDER BY id DESC");
            $req->execute();
        } else {
            // teacher : ses cours uniquement
            $req = $bdd->prepare("SELECT id, title, code, teacherId FROM courses WHERE teacherId = ? ORDER BY id DESC");
            $req->execute([$auth['uid']]);
        }
        echo json_encode(["status" => "success", "courses" => $req->fetchAll()]);
        break;
 
    // ------------------------------------------------------------------
    case 'approver_enseignant':
        requirePromoteur();
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
        requirePromoteur();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email FROM users WHERE role = 'teacher' AND isActive = 0");
        $req->execute();
        echo json_encode(["status" => "success", "teachers" => $req->fetchAll()]);
        break;
 
    case 'get_teachers':
        requirePromoteur();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email, isActive FROM users WHERE role = 'teacher'");
        $req->execute();
        echo json_encode(["status" => "success", "teachers" => $req->fetchAll()]);
        break;

    case 'promote_admin':
        requirePromoteur();
        if (empty($data['email'])) { echo json_encode(["status" => "error", "message" => "Email manquant."]); break; }
        $req = $bdd->prepare("UPDATE users SET role = 'promoteur', isActive = 1 WHERE email = ?");
        $req->execute([$data['email']]);
        echo json_encode($req->rowCount()
            ? ["status" => "success", "message" => "Utilisateur promu promoteur."]
            : ["status" => "error",   "message" => "Aucun utilisateur trouvé."]);
        break;

    case 'get_admins':
        requirePromoteur();
        $req = $bdd->prepare("SELECT id, firstName, lastName, email FROM users WHERE role = 'promoteur'");
        $req->execute();
        echo json_encode(["status" => "success", "admins" => $req->fetchAll()]);
        break;

    // ------------------------------------------------------------------
    // QCM : le professeur ajoute des questions à une leçon
    // ------------------------------------------------------------------
    case 'add_question':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }
        foreach (['lesson_id', 'question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option'] as $champ) {
            if (empty($data[$champ]) && $data[$champ] !== '0') {
                echo json_encode(["status" => "error", "message" => "Champ manquant : $champ"]);
                break 2;
            }
        }
        if (!in_array($data['correct_option'], ['a', 'b', 'c', 'd'])) {
            echo json_encode(["status" => "error", "message" => "Réponse correcte invalide."]);
            break;
        }
        $req = $bdd->prepare('INSERT INTO questions (lesson_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $req->execute([
            $data['lesson_id'], $data['question_text'],
            $data['option_a'], $data['option_b'], $data['option_c'], $data['option_d'],
            $data['correct_option']
        ]);
        echo json_encode(["status" => "success", "message" => "Question ajoutée à l'évaluation."]);
        break;

    // Étudiant : récupère les questions d'une leçon (sans la bonne réponse)
    case 'get_quiz':
        requireAuth();
        $req = $bdd->prepare('SELECT id, question_text, option_a, option_b, option_c, option_d FROM questions WHERE lesson_id = ?');
        $req->execute([$_GET['lesson_id']]);
        echo json_encode(["status" => "success", "questions" => $req->fetchAll()]);
        break;

    // Professeur : récupère les questions d'une leçon AVEC la bonne réponse (édition)
    case 'get_quiz_full':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }
        $req = $bdd->prepare('SELECT * FROM questions WHERE lesson_id = ?');
        $req->execute([$_GET['lesson_id']]);
        echo json_encode(["status" => "success", "questions" => $req->fetchAll()]);
        break;

    // ------------------------------------------------------------------
    // Étudiant soumet ses réponses au QCM d'une leçon → correction automatique
    // ------------------------------------------------------------------
    case 'submit_evaluation':
        $auth = requireAuth();
        if ($auth['role'] !== 'student') {
            echo json_encode(["status" => "error", "message" => "Seuls les étudiants peuvent passer une évaluation."]);
            break;
        }
        $lessonId = intval($data['lesson_id'] ?? 0);
        $reponses = $data['answers'] ?? []; // { question_id: 'a' }
        if (!$lessonId || empty($reponses)) {
            echo json_encode(["status" => "error", "message" => "Réponses manquantes."]);
            break;
        }
        $req = $bdd->prepare('SELECT id, correct_option FROM questions WHERE lesson_id = ?');
        $req->execute([$lessonId]);
        $questions = $req->fetchAll();
        if (empty($questions)) {
            echo json_encode(["status" => "error", "message" => "Aucune évaluation configurée pour cette leçon."]);
            break;
        }
        $bonnesReponses = 0;
        foreach ($questions as $q) {
            if (isset($reponses[$q['id']]) && $reponses[$q['id']] === $q['correct_option']) {
                $bonnesReponses++;
            }
        }
        $total   = count($questions);
        $percent = round(($bonnesReponses / $total) * 100, 1);

        $req = $bdd->prepare('INSERT INTO progress (student_id, lesson_id, score_obtained, max_score, progress_percent)
                               VALUES (?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE score_obtained = VALUES(score_obtained), max_score = VALUES(max_score), progress_percent = VALUES(progress_percent), submitted_at = NOW()');
        $req->execute([$auth['uid'], $lessonId, $bonnesReponses, $total, $percent]);

        // Vérifie si cette évaluation permet de valider un module → délivrance auto du certificat
        $certificatsObtenus = [];
        $reqModules = $bdd->prepare('SELECT DISTINCT mc.module_id FROM module_courses mc
                                      JOIN lessons l ON l.course_id = mc.course_id
                                      WHERE l.id = ?');
        $reqModules->execute([$lessonId]);
        foreach ($reqModules->fetchAll() as $m) {
            $resultatCert = verifierEtDelivrerCertificat($bdd, (int)$auth['uid'], (int)$m['module_id']);
            if ($resultatCert && !$resultatCert['already']) {
                $certificatsObtenus[] = $resultatCert;
            }
        }

        echo json_encode([
            "status"            => "success",
            "bonnes_reponses"   => $bonnesReponses,
            "total_questions"   => $total,
            "progress_percent"  => $percent,
            "nouveaux_certificats" => $certificatsObtenus
        ]);
        break;

    // ------------------------------------------------------------------
    // DEVOIRS (professeur crée, étudiant soumet un fichier)
    // ------------------------------------------------------------------
    case 'create_assignment':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }
        if (empty($data['course_id']) || empty($data['title'])) {
            echo json_encode(["status" => "error", "message" => "Cours et titre requis."]);
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
        $dueDate = !empty($data['due_date']) ? $data['due_date'] : null;
        $req = $bdd->prepare('INSERT INTO assignments (course_id, teacher_id, title, description, due_date, max_score, is_published) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $req->execute([
            $data['course_id'], $auth['uid'], $data['title'],
            $data['description'] ?? '', $dueDate, intval($data['max_score'] ?? 20)
        ]);
        echo json_encode(["status" => "success", "message" => "Devoir créé avec succès.", "assignment_id" => $bdd->lastInsertId()]);
        break;

    case 'get_assignments':
        $auth     = requireAuth();
        $courseId = intval($_GET['course_id'] ?? 0);
        if (!$courseId) { echo json_encode(["status" => "error", "message" => "course_id manquant."]); break; }

        if ($auth['role'] === 'student') {
            $req = $bdd->prepare('SELECT id, course_id, title, description, due_date, max_score
                                   FROM assignments WHERE course_id = ? AND is_published = 1
                                   ORDER BY (due_date IS NULL), due_date ASC');
            $req->execute([$courseId]);
            $assignments = $req->fetchAll();
            foreach ($assignments as &$a) {
                $reqS = $bdd->prepare('SELECT file_path, submitted_at, is_late, status FROM submissions WHERE assignment_id = ? AND student_id = ?');
                $reqS->execute([$a['id'], $auth['uid']]);
                $a['ma_soumission'] = $reqS->fetch() ?: null;
            }
        } else {
            if ($auth['role'] === 'teacher') {
                $checkOwner = $bdd->prepare('SELECT id FROM courses WHERE id = ? AND teacherId = ?');
                $checkOwner->execute([$courseId, $auth['uid']]);
                if (!$checkOwner->fetch()) {
                    echo json_encode(["status" => "error", "message" => "Ce cours ne vous appartient pas."]);
                    break;
                }
            }
            $req = $bdd->prepare('SELECT a.*, (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS nb_soumissions
                                   FROM assignments a WHERE a.course_id = ? ORDER BY a.id DESC');
            $req->execute([$courseId]);
            $assignments = $req->fetchAll();
        }
        echo json_encode(["status" => "success", "assignments" => $assignments]);
        break;

    case 'submit_assignment':
        // Même logique que add_lesson : token en header OU dans $_POST['token'] (upload multipart)
        $tokenData = verifierToken();
        if (!$tokenData && !empty($_POST['token'])) {
            $tkRaw = $_POST['token'];
            $parts = explode('.', $tkRaw);
            if (count($parts) === 3) {
                [$hdr, $pld, $sg] = $parts;
                $expSig = base64url_encode(hash_hmac('sha256', "$hdr.$pld", TOKEN_SECRET, true));
                if (hash_equals($expSig, $sg)) {
                    $decoded = json_decode(base64url_decode($pld), true);
                    if ($decoded && $decoded['exp'] >= time()) $tokenData = $decoded;
                }
            }
        }
        if (!$tokenData) { echo json_encode(["status" => "error", "message" => "Non connecté."]); break; }
        $auth = $tokenData;
        if ($auth['role'] !== 'student') {
            echo json_encode(["status" => "error", "message" => "Seuls les étudiants peuvent soumettre un devoir."]);
            break;
        }
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        if (!$assignmentId) { echo json_encode(["status" => "error", "message" => "Devoir non spécifié."]); break; }

        $reqA = $bdd->prepare('SELECT * FROM assignments WHERE id = ? AND is_published = 1');
        $reqA->execute([$assignmentId]);
        $assignment = $reqA->fetch();
        if (!$assignment) { echo json_encode(["status" => "error", "message" => "Devoir introuvable ou non publié."]); break; }

        if (empty($_FILES['submission_file']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(["status" => "error", "message" => "Fichier de soumission manquant ou erreur d'upload."]);
            break;
        }

        $extensionsAutorisees = ['pdf', 'doc', 'docx', 'zip', 'txt'];
        $mimesAutorisees = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', 'text/plain'
        ];
        $originalName = basename($_FILES['submission_file']['name']);
        $ext   = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['submission_file']['tmp_name']);
        finfo_close($finfo);
        if (!in_array($ext, $extensionsAutorisees, true) || !in_array($mime, $mimesAutorisees, true)) {
            echo json_encode(["status" => "error", "message" => "Format non autorisé (pdf, doc, docx, zip, txt uniquement)."]);
            break;
        }

        $uploadDir = __DIR__ . '/uploads/devoirs/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName    = time() . '_' . $auth['uid'] . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $destination = $uploadDir . $safeName;
        if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $destination)) {
            echo json_encode(["status" => "error", "message" => "Échec de l'enregistrement du fichier."]);
            break;
        }
        $filePath = 'uploads/devoirs/' . $safeName;

        $isLate = ($assignment['due_date'] && strtotime($assignment['due_date']) < time()) ? 1 : 0;
        $status = $isLate ? 'en_retard' : 'soumis';

        // Une soumission remplace la précédente si l'étudiant renvoie un nouveau fichier
        $req = $bdd->prepare('INSERT INTO submissions (assignment_id, student_id, file_path, is_late, status)
                               VALUES (?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), is_late = VALUES(is_late), status = VALUES(status), submitted_at = NOW()');
        $req->execute([$assignmentId, $auth['uid'], $filePath, $isLate, $status]);

        echo json_encode([
            "status"  => "success",
            "message" => $isLate ? "Devoir soumis (en retard)." : "Devoir soumis avec succès.",
            "is_late" => (bool)$isLate
        ]);
        break;

    case 'get_assignment_submissions':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }
        $assignmentId = intval($_GET['assignment_id'] ?? 0);
        $reqA = $bdd->prepare('SELECT a.*, c.teacherId FROM assignments a JOIN courses c ON c.id = a.course_id WHERE a.id = ?');
        $reqA->execute([$assignmentId]);
        $assignment = $reqA->fetch();
        if (!$assignment) { echo json_encode(["status" => "error", "message" => "Devoir introuvable."]); break; }
        if ($auth['role'] === 'teacher' && (int)$assignment['teacherId'] !== (int)$auth['uid']) {
            echo json_encode(["status" => "error", "message" => "Ce devoir ne vous appartient pas."]);
            break;
        }
        $req = $bdd->prepare('SELECT s.*, u.firstName, u.lastName, u.email
                               FROM submissions s JOIN users u ON u.id = s.student_id
                               WHERE s.assignment_id = ? ORDER BY s.submitted_at DESC');
        $req->execute([$assignmentId]);
        echo json_encode(["status" => "success", "submissions" => $req->fetchAll()]);
        break;

    // ------------------------------------------------------------------
    // MODULES (promoteur)
    // ------------------------------------------------------------------
    case 'create_module':
        requirePromoteur();
        if (empty($data['title'])) {
            echo json_encode(["status" => "error", "message" => "Titre du module requis."]);
            break;
        }
        $auth = verifierToken();
        $req = $bdd->prepare('INSERT INTO modules (title, description, promoteur_id, seuil_validation) VALUES (?, ?, ?, ?)');
        $req->execute([$data['title'], $data['description'] ?? '', $auth['uid'], intval($data['seuil_validation'] ?? 60)]);
        echo json_encode(["status" => "success", "message" => "Module créé avec succès.", "module_id" => $bdd->lastInsertId()]);
        break;

    case 'add_course_to_module':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
            break;
        }
        if (empty($data['module_id']) || empty($data['course_id'])) {
            echo json_encode(["status" => "error", "message" => "Module et cours requis."]);
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
        $req = $bdd->prepare('INSERT IGNORE INTO module_courses (module_id, course_id) VALUES (?, ?)');
        $req->execute([$data['module_id'], $data['course_id']]);
        echo json_encode(["status" => "success", "message" => "Cours ajouté au module."]);
        break;

    case 'remove_course_from_module':
        $auth = requireAuth();
        if (!in_array($auth['role'], ['teacher', 'promoteur'])) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]);
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
        $req = $bdd->prepare('DELETE FROM module_courses WHERE module_id = ? AND course_id = ?');
        $req->execute([$data['module_id'], $data['course_id']]);
        echo json_encode(["status" => "success", "message" => "Cours retiré du module."]);
        break;

    case 'get_modules':
        requireAuth();
        $req = $bdd->prepare('SELECT m.*, u.firstName, u.lastName FROM modules m JOIN users u ON u.id = m.promoteur_id ORDER BY m.id DESC');
        $req->execute();
        $modules = $req->fetchAll();
        foreach ($modules as &$mod) {
            $reqC = $bdd->prepare('SELECT c.id, c.title, c.code FROM module_courses mc JOIN courses c ON c.id = mc.course_id WHERE mc.module_id = ?');
            $reqC->execute([$mod['id']]);
            $mod['courses'] = $reqC->fetchAll();
        }
        echo json_encode(["status" => "success", "modules" => $modules]);
        break;

    // Progression d'un étudiant sur un module précis
    case 'get_module_progress':
        $auth = requireAuth();
        $studentId = ($auth['role'] === 'promoteur' && isset($_GET['student_id'])) ? intval($_GET['student_id']) : $auth['uid'];
        $moduleId  = intval($_GET['module_id']);

        $reqC = $bdd->prepare('SELECT course_id FROM module_courses WHERE module_id = ?');
        $reqC->execute([$moduleId]);
        $courseIds = array_column($reqC->fetchAll(), 'course_id');
        if (empty($courseIds)) { echo json_encode(["status" => "success", "total_lecons" => 0, "lecons_faites" => 0, "moyenne" => 0]); break; }
        $ph = implode(',', array_fill(0, count($courseIds), '?'));

        $reqL = $bdd->prepare("SELECT id FROM lessons WHERE course_id IN ($ph)");
        $reqL->execute($courseIds);
        $lessonIds = array_column($reqL->fetchAll(), 'id');
        $totalLecons = count($lessonIds);

        $moyenne = 0; $leconsFaites = 0;
        if ($totalLecons > 0) {
            $phL = implode(',', array_fill(0, count($lessonIds), '?'));
            $reqP = $bdd->prepare("SELECT progress_percent FROM progress WHERE student_id = ? AND lesson_id IN ($phL)");
            $reqP->execute(array_merge([$studentId], $lessonIds));
            $scores = array_column($reqP->fetchAll(), 'progress_percent');
            $leconsFaites = count($scores);
            $moyenne = $leconsFaites ? array_sum($scores) / $leconsFaites : 0;
        }
        echo json_encode([
            "status" => "success",
            "total_lecons" => $totalLecons,
            "lecons_faites" => $leconsFaites,
            "moyenne" => round($moyenne, 1)
        ]);
        break;

    // ------------------------------------------------------------------
    // CERTIFICATS
    // ------------------------------------------------------------------
    case 'get_my_certificates':
        $auth = requireAuth();
        $req = $bdd->prepare('SELECT c.*, m.title AS module_title, u.firstName, u.lastName
                               FROM certificates c
                               JOIN modules m ON m.id = c.module_id
                               JOIN users u ON u.id = c.student_id
                               WHERE c.student_id = ? ORDER BY c.delivered_at DESC');
        $req->execute([$auth['uid']]);
        echo json_encode(["status" => "success", "certificates" => $req->fetchAll()]);
        break;

    case 'get_all_certificates':
        requirePromoteur();
        $req = $bdd->prepare('SELECT c.*, m.title AS module_title, u.firstName, u.lastName, u.email
                               FROM certificates c
                               JOIN modules m ON m.id = c.module_id
                               JOIN users u ON u.id = c.student_id
                               ORDER BY c.delivered_at DESC');
        $req->execute();
        echo json_encode(["status" => "success", "certificates" => $req->fetchAll()]);
        break;

    // Vérification PUBLIQUE d'un certificat (aucune authentification requise, comme un vrai vérificateur)
    case 'verify_certificate':
        $code = trim($_GET['code'] ?? '');
        if (!$code) { echo json_encode(["status" => "error", "message" => "Code manquant."]); break; }
        $req = $bdd->prepare('SELECT c.code, c.average_score, c.delivered_at, m.title AS module_title,
                                      u.firstName, u.lastName
                               FROM certificates c
                               JOIN modules m ON m.id = c.module_id
                               JOIN users u ON u.id = c.student_id
                               WHERE c.code = ?');
        $req->execute([$code]);
        $cert = $req->fetch();
        if (!$cert) {
            echo json_encode(["status" => "error", "valid" => false, "message" => "Certificat introuvable ou invalide."]);
            break;
        }
        echo json_encode(["status" => "success", "valid" => true, "certificate" => $cert]);
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
