import {User, Cours, Inscription} from './models.js';
import { z } from 'https://cdn.jsdelivr.net/npm/zod@3.23.8/+esm';
 
const model =z.object({
    nom: z.string().min(2, {message:"Le nom doit contenir au moins 2 caracteres"}),
    prenom: z.string().min(2,{message:"Le nom doit contenir au moins 2 caracteres"}),
    email:z.string().email({message: "Format non pris en charge"}),
    password: z.string().min(8,{message: "Pour un mot de passe sur il faut au moins 8 caracteres"}),
    role: z.enum(['student','teacher','admin'],{message: "doit etre defini au prealable"})
});
 
const creercours = z.object({
    titre: z.string().min(3, {message: "le titre doit contenir au moins 3 caracteres"}),
    code: z.string().min(6, {message: "le code du cours(ex:INF201)"}).transform(val => val.toUpperCase()),
    teacherid: z.number().int({message: "identifiant du professeur"}),
    description: z.string().min(3, {message: "la description doit comtenir au moins 3 caracteres"}),
    maxetudiant: z.number().int().positive({message: "le nombre d'etudiant est strictement positif"})
});
 
export async function ajouterUser(données_form) {
    const confirmer = model.safeParse(données_form);
    if (!confirmer.success) {
        console.error("Erreurs: ",confirmer.error.flatten().fieldErrors);
        alert("Donnes du formulaires invalides");
        return;
    }
    try {
       const response =  await fetch('serveur.php?action=register',{
            method: 'POST',
            headers: {'Content-type':'application/json'},
            body: JSON.stringify(confirmer.data)
        });
        const result = await response.json();
        alert(result.message);
        if (result.status === 'success') {
            window.location.href= 'index.html';
        }
    } catch (error) {
        console.error("Erreur d'inscription:", error);
    }
}
 
export async function connection(email,password) {
    try {
        const response = await fetch('serveur.php?action=login',{
        method: 'POST',
        headers: {'Content-type': 'application/json'},
        body: JSON.stringify({email,password})
        });
        const result = await response.json();
        if (result.status === 'success') {
            alert(`Ravi de vous revoir ${result.user.firstName}`);
            localStorage.setItem('User', JSON.stringify(result.user));
            window.location.href='accueil.html';
        }else{
            alert(result.message);
        }
    } catch (error) {
        console.error("Erreur de connexion", error);
    }
}
 
export async function CreeCours(données_form){
    const valider = creercours.safeParse(données_form);
    if (!valider.success) {
        console.error("Erreur: ",valider.error.flatten().fieldErrors);
        alert("Donnees du formulaire invalides");
        return;
    }
    try {
        const response = await fetch('serveur.php?action=create_course',{
            method: 'POST',
            headers: {'Content-type': 'application/json'},
            body: JSON.stringify(valider.data)
        });
        const result = await response.json();
        alert(result.message);
        if (result.status === 'success') {
            location.reload();
        }
    } catch (error) {
        console.error("Erreur lors de la creation du cours:", error);
    }
}
 
export async function soumettre(studentId, lessonId, scoreObtenu, scoreMax) {
    try {
        const reponse = await fetch('serveur.php?action=submit_evaluation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_id: studentId, lesson_id: lessonId, score_obtained: scoreObtenu, max_score: scoreMax })
        });
        const resultat = await reponse.json();
        
        if (resultat.status === 'success') {
            alert(`Évaluation enregistrée. Progression mise à jour : ${resultat.progress_percent}%`);
            return resultat.progress_percent;
        }
    } catch (error) {
        console.error("Erreur lors de la soumission de l'évaluation:", error);
    }
}
 
export function passerEvaluation(lessonId){
    const studentId = JSON.parse(localStorage.getItem('User') || '{}').id;
    const scoreObtenu = parseInt(prompt("Entrer la note obtenue:"),10);
    const scoreMax = parseInt(prompt("Entrer la note maximale :", "20"),10);
    if (isNaN(scoreObtenu) || isNaN(scoreMax)){
        alert("Valeurs invalides.");
        return;
    }
    soumettre(studentId, lessonId, scoreObtenu, scoreMax);
}
 
export async function chargerLeconsDuCours(courseId, conteneurHtml) {
    try {
        const response = await fetch(`serveur.php?action=get_lessons&course_id=${courseId}`);
        const data = await response.json();
 
        conteneurHtml.innerHTML = '';
        if (data.status !== 'success' || data.lessons.length === 0) {
            conteneurHtml.innerHTML = '<p class="empty-state">Aucune leçon disponible pour ce cours.</p>';
            return;
        }
        data.lessons.forEach(lecon => {
            const elementHtml = document.createElement('div');
            elementHtml.className = "lecon-item";
            if (lecon.content_type === 'pdf') {
                elementHtml.innerHTML = `
                    <h3>${lecon.title} <span class="badge badge-pdf">PDF</span></h3>
                    <a href="${lecon.file_path}" download class="btn-download">📥 Télécharger le cours</a>
                    <button class="btn-evaluation">Passer l'évaluation</button>
                `;
            } else {
                elementHtml.innerHTML = `
                    <h3>${lecon.title} <span class="badge badge-video">Vidéo</span></h3>
                    <video src="${lecon.file_path}" controls width="320"></video>
                    <br><button class="btn-evaluation">Passer l'évaluation</button>
                `;
            }
            elementHtml.querySelector('.btn-evaluation').addEventListener('click', () => passerEvaluation(lecon.id));
            conteneurHtml.appendChild(elementHtml);
        });
    } catch (error) {
        console.error("Erreur de chargement des leçons:", error);
        conteneurHtml.innerHTML = '<p>Erreur de chargement des leçons.</p>';
    }
}
 
// Charge les cours du professeur connecté et les affiche dans le conteneur donné
export async function chargerMesCours(conteneurHtml, onSelectCours) {
    try {
        const response = await fetch('serveur.php?action=get_teacher_courses');
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
                <button class="btn-submit btn-small">Gérer les leçons →</button>
            `;
            div.querySelector('button').addEventListener('click', () => onSelectCours(cours));
            conteneurHtml.appendChild(div);
        });
    } catch (error) {
        console.error("Erreur chargement des cours:", error);
    }
}
