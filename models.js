//Attributs : id (PK), firstName, lastName, email (Unique), passwordHash, role, isActive.

export class User {
    constructor(id,prenom,Nom,email,passwordhashe,role) {
        this.identifiant = id;
        this.firstname = prenom;
        this.name  =Nom;
        this.email= email;
        this.mot_de_passe = passwordhashe;
        this.role = role;
        this.estActif = false;
        this.status = "Offline";
    }   
     //Méthodes / Scopes :prototype.validPassword(password) : Compare le mot de passe fourni avec le hash Bcrypt.
    async validPassword(mot){
        return await bcrypt.compare(mot,this.passwordhashe);
    }
}

//Attributs : id (PK), title, code (Unique), description, teacherId (FK), status, maxStudents.

export class Cours {
    constructor(idcours, title,code,description,teacherid,Nbretd) {
        this.idcours = idcours;
        this.titre = title;
        this.code = code;
        this.description = description;
        this.teacherid= teacherid;
        this.status = "En_attente";
        this.maxEtudiant = Nbretd;
    }
}

//Attributs : id (PK), studentId (FK), courseId (FK), status, enrolledAt.

export class Inscription{
    constructor(idinscript,studentId, coursId){
        this.idinscription = idinscript;
        this.studentId= studentId;
        this.coursId= coursId;
        this.status = "En_attente";
        this.coursId = coursId;
        this.dateInscript = Date.now();
    }

}

//Attributs : id (PK), courseId (FK), teacherId (FK), title, description, dueDate, maxScore,isPublished.

export class Devoirs {
    constructor(Idassig,coursId,teacherid,titre,description,maxscore) {
        this.id = Idassig;
        this.idcours = coursId;
        this.idteach = teacherid;
        this.titre = titre;
        this.libeller= description;
        this.datedevoir = null;
        this.score = maxscore||20;
        this.publier = false;
    }
}

//Attributs : id (PK), assignmentId (FK), studentId (FK), filePath, submittedAt, isLate, status

export class Soumetttre {
    constructor(id,devoirId,idetd,filepath,status) {
        this.submitid = id;
        this.assigmentId = devoirId;
        this.filepath = filepath;
        this.submiAt = Date.now();
        this.isLate = false;
        this.status = "submit";
    }
}
