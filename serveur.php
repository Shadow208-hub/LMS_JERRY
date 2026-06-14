<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET");

session_start();

$host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'lms_jerrydb';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: ''; 

try {
    $bdd = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Échec de connexion à la base de données."]);
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
            if ($user['isActive'] === 0) {
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
