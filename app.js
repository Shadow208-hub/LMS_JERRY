import { User, Cours, Inscription } from './models.js';
import { z } from 'https://cdn.jsdelivr.net/npm/zod@3.23.8/+esm';
 
// ----------------------------------------------------------------
// Token : lu depuis localStorage, injecté dans chaque requête
// ----------------------------------------------------------------
function getToken() {
    return localStorage.getItem('token') || '';
}
 
// Headers communs avec Authorization
function authHeaders() {
    return {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${getToken()}`
    };
}
 
// fetch authentifié (GET)
function fetchAuth(url) {
    return fetch(url, { headers: { 'Authorization': `Bearer ${getToken()}` } });
}
 
// ----------------------------------------------------------------
// Schémas Zod
// ----------------------------------------------------------------
const model = z.object({
    nom:      z.string().min(2,  { message: "Le nom doit contenir au moins 2 caractères" }),
    prenom:   z.string().min(2,  { message: "Le prénom doit contenir au moins 2 caractères" }),
    email:    z.string().email(  { message: "Format email non pris en charge" }),
    password: z.string().min(8,  { message: "Mot de passe : 8 caractères minimum" }),
    role:     z.enum(['student', 'teacher', 'admin'], { message: "Rôle invalide" })
});
 
const creercours = z.object({
    titre:       z.string().min(3,  { message: "Le titre doit contenir au moins 3 caractères" }),
    code:        z.string().min(4,  { message: "Code du cours (ex: INF201)" }).transform(val => val.toUpperCase()),
    teacherid:   z.number().int(    { message: "Identifiant professeur invalide" }),
    description: z.string().min(3,  { message: "La description doit contenir au moins 3 caractères" }),
    maxetudiant: z.number().int().positive({ message: "Nombre d'étudiants invalide" })
});
 
// ----------------------------------------------------------------
// Inscription
// ----------------------------------------------------------------
export async function ajouterUser(données_form) {
    const confirmer = model.safeParse(données_form);
    if (!confirmer.success) {
        console.error("Erreurs:", confirmer.error.flatten().fieldErrors);
        alert("Données du formulaire invalides.");
        return;
    }
    try {
        const response = await fetch('serveur.php?action=register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(confirmer.data)
        });
        const result = await response.json();
        alert(result.message);
        if (result.status === 'success') window.location.href = 'index.html';
    } catch (error) {
        console.error("Erreur d'inscription:", error);
    }
}
 
// ----------------------------------------------------------------
// Connexion — sauvegarde le token ET l'objet user
// ----------------------------------------------------------------
export async function connection(email, password) {
    try {
        const response = await fetch('serveur.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
        });
        const result = await response.json();
        if (result.status === 'success') {
            alert(`Ravi de vous revoir ${result.user.firstName} !`);
            localStorage.setItem('token', result.token);
            localStorage.setItem('User', JSON.stringify(result.user));
            window.location.href = 'accueil.html';
        } else {
            alert(result.message);
        }
    } catch (error) {
        console.error("Erreur de connexion:", error);
    }
}
 
// ----------------------------------------------------------------
// Créer un cours
// ----------------------------------------------------------------
export async function CreeCours(données_form) {
    const valider = creercours.safeParse(données_form);
    if (!valider.success) {
        console.error("Erreur:", valider.error.flatten().fieldErrors);
        alert("Données du formulaire invalides.");
        return;
    }
    try {
        const response = await fetch('serveur.php?action=create_course', {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify(valider.data)
        });
        const result = await response.json();
        alert(result.message);
        if (result.status === 'success') location.reload();
    } catch (error) {
        console.error("Erreur lors de la création du cours:", error);
    }
}
 
// ----------------------------------------------------------------
// Évaluation
// ----------------------------------------------------------------
export async function soumettre(studentId, lessonId, scoreObtenu, scoreMax) {
    try {
        const reponse = await fetch('serveur.php?action=submit_evaluation', {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify({ student_id: studentId, lesson_id: lessonId, score_obtained: scoreObtenu, max_score: scoreMax })
        });
        const resultat = await reponse.json();
        if (resultat.status === 'success') {
            alert(`Évaluation enregistrée. Progression : ${resultat.progress_percent}%`);
            return resultat.progress_percent;
        }
    } catch (error) {
        console.error("Erreur lors de la soumission:", error);
    }
}
 
export function passerEvaluation(lessonId) {
    const studentId  = JSON.parse(localStorage.getItem('User') || '{}').id;
    const scoreObtenu = parseInt(prompt("Note obtenue :"), 10);
    const scoreMax    = parseInt(prompt("Note maximale :", "20"), 10);
    if (isNaN(scoreObtenu) || isNaN(scoreMax)) { alert("Valeurs invalides."); return; }
    soumettre(studentId, lessonId, scoreObtenu, scoreMax);
}
 
// ----------------------------------------------------------------
// Charger les leçons d'un cours
// ----------------------------------------------------------------
export async function chargerLeconsDuCours(courseId, conteneurHtml) {
    try {
        const response = await fetchAuth(`serveur.php?action=get_lessons&course_id=${courseId}`);
        const data = await response.json();
        conteneurHtml.innerHTML = '';
        if (data.status !== 'success' || data.lessons.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Aucune leçon disponible pour ce cours.</p>';
            return;
        }
        data.lessons.forEach(lecon => {
            const el = document.createElement('div');
            el.className = 'lecon-item';
            if (lecon.content_type === 'pdf') {
                el.innerHTML = `
                    <h3>${lecon.title} <span class="badge badge-pdf">PDF</span></h3>
                    <a href="${lecon.file_path}" target="_blank" class="btn-download">📄 Ouvrir le PDF</a>
                    <a href="${lecon.file_path}" download class="btn-download">📥 Télécharger le cours</a>
                    <button class="btn-evaluation">Passer l'évaluation</button>`;
            } else {
                // Convertir les URLs YouTube en lien embed pour iframe
                const videoUrl = lecon.file_path;
                let embedHtml = '';
                const ytMatch = videoUrl.match(
                    /(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
                );
                const driveMatch = videoUrl.match(
                    /drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/
                );
 
                if (ytMatch) {
                    // YouTube → iframe embed
                    embedHtml = `<iframe width="320" height="180"
                        src="https://www.youtube.com/embed/${ytMatch[1]}"
                        frameborder="0" allowfullscreen style="display:block;margin:8px 0;border-radius:8px;"></iframe>`;
                } else if (driveMatch) {
                    // Google Drive → iframe preview
                    embedHtml = `<iframe width="320" height="180"
                        src="https://drive.google.com/file/d/${driveMatch[1]}/preview"
                        frameborder="0" allowfullscreen style="display:block;margin:8px 0;border-radius:8px;"></iframe>`;
                } else {
                    // Lien direct (mp4, etc.) → balise video native
                    embedHtml = `<video src="${videoUrl}" controls width="320" style="display:block;margin:8px 0;border-radius:8px;"></video>`;
                }
 
                el.innerHTML = `
                    <h3>${lecon.title} <span class="badge badge-video">Vidéo</span></h3>
                    ${embedHtml}
                    <a href="${videoUrl}" target="_blank" class="btn-download">🔗 Ouvrir dans un nouvel onglet</a>
                    <br><button class="btn-evaluation">Passer l'évaluation</button>`;
            }
            el.querySelector('.btn-evaluation').addEventListener('click', () => passerEvaluation(lecon.id));
            conteneurHtml.appendChild(el);
        });
    } catch (error) {
        console.error("Erreur de chargement des leçons:", error);
        conteneurHtml.innerHTML = '<p>Erreur de chargement des leçons.</p>';
    }
}
 
// ----------------------------------------------------------------
// Charger les cours du professeur connecté
// ----------------------------------------------------------------
export async function chargerMesCours(conteneurHtml, onSelectCours) {
    try {
        const response = await fetchAuth('serveur.php?action=get_teacher_courses');
        const data = await response.json();
        conteneurHtml.innerHTML = '';
        if (data.status !== 'success' || data.courses.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Vous n\'avez pas encore créé de cours.</p>';
            return;
        }
        data.courses.forEach(cours => {
            const div = document.createElement('div');
            div.className = 'course-card';
            div.innerHTML = `
                <div class="course-info">
                    <h3>${cours.title}</h3>
                    <span class="course-code">${cours.code}</span>
                    <p>${cours.description || ''}</p>
                    <small>Max : ${cours.maxStudents} étudiant(s)</small>
                </div>
                <button class="btn-submit btn-small">Gérer les leçons →</button>`;
            div.querySelector('button').addEventListener('click', () => onSelectCours(cours));
            conteneurHtml.appendChild(div);
        });
    } catch (error) {
        console.error("Erreur chargement des cours:", error);
    }
}
 
