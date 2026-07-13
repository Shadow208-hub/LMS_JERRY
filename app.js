import { User, Cours, Inscription } from './models.js';
import { z } from 'https://cdn.jsdelivr.net/npm/zod@3.23.8/+esm';
 
// ----------------------------------------------------------------
// Token : lu depuis sessionStorage, injecté dans chaque requête
// ----------------------------------------------------------------
function getToken() {
    return sessionStorage.getItem('token') || '';
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
    role:     z.enum(['student', 'teacher'], { message: "Rôle invalide" })
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
            sessionStorage.setItem('token', result.token);
            sessionStorage.setItem('User', JSON.stringify(result.user));
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
// Évaluation (QCM auto-corrigé, après chaque leçon)
// ----------------------------------------------------------------

// Étudiant : ouvre le QCM d'une leçon dans un conteneur donné
export async function ouvrirEvaluation(lessonId, conteneurHtml) {
    try {
        const res  = await fetchAuth(`serveur.php?action=get_quiz&lesson_id=${lessonId}`);
        const data = await res.json();
        if (data.status !== 'success' || data.questions.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Aucune évaluation configurée pour cette leçon pour le moment.</p>';
            return;
        }
        conteneurHtml.innerHTML = `
            <form id="quizForm-${lessonId}" class="quiz-form">
                ${data.questions.map((q, i) => `
                    <div class="quiz-question">
                        <p><strong>${i + 1}. ${q.question_text}</strong></p>
                        ${['a','b','c','d'].map(opt => `
                            <label class="quiz-option">
                                <input type="radio" name="q${q.id}" value="${opt}" required>
                                ${q['option_' + opt]}
                            </label>
                        `).join('')}
                    </div>
                `).join('')}
                <button type="submit" class="btn-submit">Valider l'évaluation</button>
            </form>
            <div id="quizResult-${lessonId}"></div>
        `;
        document.getElementById(`quizForm-${lessonId}`).addEventListener('submit', async (e) => {
            e.preventDefault();
            const answers = {};
            data.questions.forEach(q => {
                const checked = e.target.querySelector(`input[name="q${q.id}"]:checked`);
                if (checked) answers[q.id] = checked.value;
            });
            await soumettreEvaluation(lessonId, answers, document.getElementById(`quizResult-${lessonId}`));
        });
    } catch (error) {
        console.error("Erreur de chargement du QCM:", error);
        conteneurHtml.innerHTML = '<p>Erreur de chargement de l\'évaluation.</p>';
    }
}

// Envoie les réponses au serveur, qui corrige et calcule la progression
export async function soumettreEvaluation(lessonId, answers, zoneResultat) {
    try {
        const res  = await fetch('serveur.php?action=submit_evaluation', {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify({ lesson_id: lessonId, answers })
        });
        const data = await res.json();
        if (data.status !== 'success') { alert(data.message); return; }
        let html = `<p class="quiz-score">✅ ${data.bonnes_reponses}/${data.total_questions} bonnes réponses — Progression : <strong>${data.progress_percent}%</strong></p>`;
        if (data.nouveaux_certificats && data.nouveaux_certificats.length > 0) {
            html += data.nouveaux_certificats.map(c =>
                `<p class="cert-alert">🎓 Félicitations, tu viens de valider un module et d'obtenir un certificat ! Code : <code>${c.code.slice(0,16)}...</code></p>`
            ).join('');
        }
        if (zoneResultat) zoneResultat.innerHTML = html; else alert(`Score : ${data.progress_percent}%`);
    } catch (error) {
        console.error("Erreur lors de la soumission du QCM:", error);
    }
}

// Professeur : ajoute une question au QCM d'une leçon
export async function ajouterQuestion(lessonId, donnees) {
    try {
        const res = await fetch('serveur.php?action=add_question', {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify({ lesson_id: lessonId, ...donnees })
        });
        const data = await res.json();
        alert(data.message);
        return data.status === 'success';
    } catch (error) {
        console.error("Erreur lors de l'ajout de la question:", error);
        return false;
    }
}

// Professeur : liste les questions déjà créées pour une leçon
export async function chargerQuizProf(lessonId, conteneurHtml) {
    try {
        const res  = await fetchAuth(`serveur.php?action=get_quiz_full&lesson_id=${lessonId}`);
        const data = await res.json();
        if (data.status !== 'success' || data.questions.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Aucune question pour l\'instant.</p>';
            return;
        }
        conteneurHtml.innerHTML = '<ol>' + data.questions.map(q =>
            `<li>${q.question_text} <em>(bonne réponse : ${q.correct_option.toUpperCase()})</em></li>`
        ).join('') + '</ol>';
    } catch (error) {
        conteneurHtml.innerHTML = '<p>Erreur de chargement.</p>';
    }
}

// ----------------------------------------------------------------
// Modules & Certificats (promoteur)
// ----------------------------------------------------------------
export async function creerModule(donnees) {
    try {
        const res = await fetch('serveur.php?action=create_module', {
            method: 'POST', headers: authHeaders(), body: JSON.stringify(donnees)
        });
        const data = await res.json();
        alert(data.message);
        return data;
    } catch (error) {
        console.error("Erreur lors de la création du module:", error);
    }
}

export async function ajouterCoursAuModule(moduleId, courseId) {
    try {
        const res = await fetch('serveur.php?action=add_course_to_module', {
            method: 'POST', headers: authHeaders(), body: JSON.stringify({ module_id: moduleId, course_id: courseId })
        });
        const data = await res.json();
        alert(data.message);
        return data.status === 'success';
    } catch (error) {
        console.error("Erreur lors de l'ajout du cours au module:", error);
    }
}

export async function chargerModules(conteneurHtml, options = {}) {
    try {
        const res  = await fetchAuth('serveur.php?action=get_modules');
        const data = await res.json();
        conteneurHtml.innerHTML = '';
        if (data.status !== 'success' || data.modules.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Aucun module créé pour le moment.</p>';
            return;
        }
        data.modules.forEach(m => {
            const div = document.createElement('div');
            div.className = 'course-card module-card';
            div.innerHTML = `
                <div class="course-info">
                    <h3>🧩 ${m.title}</h3>
                    <p>${m.description || ''}</p>
                    <small>Seuil de validation : ${m.seuil_validation}% — ${m.courses.length} cours associé(s)</small>
                    <div class="module-progress" id="module-progress-${m.id}"></div>
                </div>
                ${options.onSelect ? '<button class="btn-submit btn-small">Voir les cours →</button>' : ''}`;
            if (options.onSelect) {
                div.querySelector('button').addEventListener('click', () => options.onSelect(m));
            }
            conteneurHtml.appendChild(div);
            if (options.onRender) options.onRender(m, div);
        });
    } catch (error) {
        console.error("Erreur chargement des modules:", error);
    }
}

// Affiche les cours d'un module donné (appelé après clic sur un module),
// chaque cours étant cliquable pour ouvrir ses leçons.
export function afficherCoursDuModule(module, conteneurHtml, role, onCourseSelect) {
    conteneurHtml.innerHTML = '';
    if (!module.courses || module.courses.length === 0) {
        conteneurHtml.innerHTML = '<p class="empty-state">Aucun cours associé à ce module pour le moment.</p>';
        return;
    }
    module.courses.forEach(cours => {
        const div = document.createElement('div');
        div.className = 'course-card';
        div.innerHTML = `
            <div class="course-info">
                <h3>${cours.title}</h3>
                <span class="course-code">${cours.code}</span>
            </div>
            <button class="btn-submit btn-small">Voir les leçons →</button>`;
        div.querySelector('button').addEventListener('click', () => onCourseSelect(cours));
        conteneurHtml.appendChild(div);
    });
}


export async function chargerProgressionModule(moduleId, conteneurHtml) {
    try {
        const res  = await fetchAuth(`serveur.php?action=get_module_progress&module_id=${moduleId}`);
        const data = await res.json();
        if (data.status !== 'success') return;
        conteneurHtml.innerHTML = `<small>Progression : ${data.lecons_faites}/${data.total_lecons} leçon(s) évaluée(s) — Moyenne : ${data.moyenne}%</small>`;
    } catch (error) {
        console.error("Erreur progression module:", error);
    }
}

export async function chargerMesCertificats(conteneurHtml) {
    try {
        const res  = await fetchAuth('serveur.php?action=get_my_certificates');
        const data = await res.json();
        conteneurHtml.innerHTML = '';
        if (data.status !== 'success' || data.certificates.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Aucun certificat obtenu pour le moment. Termine un module pour en recevoir un !</p>';
            return;
        }
        data.certificates.forEach(c => {
            const div = document.createElement('div');
            div.className = 'certificate-card';
            const verifyUrl = `${window.location.origin}${window.location.pathname.replace('accueil.html','')}verifier-certificat.html?code=${c.code}`;
            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=${encodeURIComponent(verifyUrl)}`;
            div.innerHTML = `
                <h3>🎓 ${c.module_title}</h3>
                <p>Score moyen : <strong>${c.average_score}%</strong></p>
                <p><small>Délivré le ${new Date(c.delivered_at).toLocaleDateString('fr-FR')}</small></p>
                <img src="${qrUrl}" alt="QR code de vérification" class="cert-qr">
                <a href="${verifyUrl}" target="_blank" class="btn-download">🔗 Voir / vérifier le certificat</a>`;
            conteneurHtml.appendChild(div);
        });
    } catch (error) {
        console.error("Erreur chargement des certificats:", error);
    }
}

// ----------------------------------------------------------------
// Charger les leçons d'un cours
// ----------------------------------------------------------------
export async function chargerLeconsDuCours(courseId, conteneurHtml, role = 'student') {
    try {
        const response = await fetchAuth(`serveur.php?action=get_lessons&course_id=${courseId}`);
        const data = await response.json();
        conteneurHtml.innerHTML = '';
        if (data.status !== 'success' || data.lessons.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Aucune leçon disponible pour ce cours.</p>';
            return;
        }
        const estEnseignant = ['teacher', 'promoteur'].includes(role);
        const libelleBtn = estEnseignant ? "📝 Gérer l'évaluation (QCM)" : "Passer l'évaluation";

        data.lessons.forEach(lecon => {
            const el = document.createElement('div');
            el.className = 'lecon-item';
            if (lecon.content_type === 'pdf') {
                el.innerHTML = `
                    <h3>${lecon.title} <span class="badge badge-pdf">PDF</span></h3>
                    <a href="${lecon.file_path}" target="_blank" class="btn-download">📄 Ouvrir le PDF</a>
                    <a href="${lecon.file_path}" download class="btn-download">📥 Télécharger le cours</a>
                    <button class="btn-evaluation">${libelleBtn}</button>
                    <div class="eval-zone hidden"></div>`;
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
                    <br><button class="btn-evaluation">${libelleBtn}</button>
                    <div class="eval-zone hidden"></div>`;
            }
            const zone = el.querySelector('.eval-zone');
            el.querySelector('.btn-evaluation').addEventListener('click', async () => {
                zone.classList.toggle('hidden');
                if (zone.classList.contains('hidden') || zone.dataset.loaded) return;
                zone.dataset.loaded = '1';
                if (estEnseignant) {
                    await afficherGestionQuiz(lecon.id, zone);
                } else {
                    await ouvrirEvaluation(lecon.id, zone);
                }
            });
            conteneurHtml.appendChild(el);
        });
    } catch (error) {
        console.error("Erreur de chargement des leçons:", error);
        conteneurHtml.innerHTML = '<p>Erreur de chargement des leçons.</p>';
    }
}

// Professeur : formulaire d'ajout de question + liste des questions existantes pour une leçon
async function afficherGestionQuiz(lessonId, conteneurHtml) {
    conteneurHtml.innerHTML = `
        <h4>Questions existantes</h4>
        <div class="quiz-list-${lessonId}"></div>
        <hr style="margin:12px 0;">
        <h4>Ajouter une question</h4>
        <form class="add-question-form">
            <div class="form-group"><label>Question :</label><input type="text" class="q-text" required></div>
            <div class="form-group"><label>Option A :</label><input type="text" class="q-a" required></div>
            <div class="form-group"><label>Option B :</label><input type="text" class="q-b" required></div>
            <div class="form-group"><label>Option C :</label><input type="text" class="q-c" required></div>
            <div class="form-group"><label>Option D :</label><input type="text" class="q-d" required></div>
            <div class="form-group">
                <label>Bonne réponse :</label>
                <select class="q-correct">
                    <option value="a">A</option><option value="b">B</option>
                    <option value="c">C</option><option value="d">D</option>
                </select>
            </div>
            <button type="submit" class="btn-submit btn-small">Ajouter la question</button>
        </form>`;
    const listeZone = conteneurHtml.querySelector(`.quiz-list-${lessonId}`);
    await chargerQuizProf(lessonId, listeZone);
    conteneurHtml.querySelector('.add-question-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        const ok = await ajouterQuestion(lessonId, {
            question_text: f.querySelector('.q-text').value,
            option_a: f.querySelector('.q-a').value,
            option_b: f.querySelector('.q-b').value,
            option_c: f.querySelector('.q-c').value,
            option_d: f.querySelector('.q-d').value,
            correct_option: f.querySelector('.q-correct').value
        });
        if (ok) { f.reset(); await chargerQuizProf(lessonId, listeZone); }
    });
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
