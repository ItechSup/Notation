<?php

require_once __DIR__.'/../vendor/autoload.php';

$app = new Silex\Application();
$app['debug'] = true;

/*
 * On a très envie d'accéder à une base de données
 */
$app->register(new Silex\Provider\DoctrineServiceProvider(), [
    'db.options' => [
        'driver'   => 'pdo_sqlite',
        'path'     => __DIR__.'/../data/app.db',
    ],
]);

/*
 * On a envie d'avoir le json déjà décodé dans nos actions. A chaque fois
 */
$app->before(function (Symfony\Component\HttpFoundation\Request $request) {
    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});

/*
 * On a très envie de gérer nos erreurs d'ici
 */
$app->error(function() use ($app){
    if (!$app['debug']) {
        return $app->json("Erreur.");
    }
});

/*
 * On va aussi convertir du paramètre à la volée depuis l'url, parce que c'est la classe à Dallas
 */
$personneProvider = function ($id) use ($app) {
    if (!$personne = Personne::load($app, $id)) {
        throw new Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Erreur.');
    }
    return $personne;
};

$sessionProvider = function ($id) use ($app) {
    if (!$session = Session::load($app, $id)) {
        throw new Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Erreur.');
    }
    return $session;
};


/*
 * Utiliser des classes abstraites donne à certain un sentiment de pusiosance intelectuelle
 */
abstract class Modele
{
    protected $app;

    /*
     * PHP nous offre de la reflexivité, ceci est fourni à titre d'exemple.
     * Ce genre d'implémentation pose deux problème :
     * 1- C'est très inférieur en terme de performance par rapport à l'écriture en propre d'accesseurs et mutateurs
     * 2- Ca viole sauvagement le principe de l'encapsulation. Et il aime pas trop trop ça.
     * 3- C'est peu lisible
     */
    public function __get($attribut) {
        if (property_exists($this, $attribut)) {
            $reflexion = new ReflectionProperty($this, $attribut);
            $reflexion->setAccessible($attribut);
            return $reflexion->getValue($this);
        }
    }

    public function __set($attribut, $valeur) {
        if (property_exists($this, $attribut)) {
            $reflexion = new ReflectionProperty($this, $attribut);
            $reflexion->setAccessible($attribut);
            $reflexion->setValue($this, $valeur);
        }
        return $this;
    }

    public function __construct($app) {
        $this->app = $app;
    }

    /*
     * On n'aime toujours pas les méthodes statiques, mais là on se l'authorise parce que ça colle bien avec le reste
     */
    public static function load($app, $id) {
        $sql = "SELECT * FROM ".get_called_class()." WHERE id = ?";
        return $app['db']->executeQuery($sql, [(int) $id])->fetchObject(get_called_class(),['app' => $app]);
    }

    public abstract function save();

    public abstract function update();

    public abstract function delete();

    public abstract function getJsonRepresentation();
}

class Personne extends Modele
{
    private $id;
    private $nom;
    private $prenom;
    private $sessionsSuivies;
    private $sessionsMenees;

    /**
     * Personne constructor.
     *
     * On ne saluera pas la présence de requêtes SQL
     *
     * @param $app
     */
    public function __construct($app)
    {
        parent::__construct($app);
        $sql = "SELECT id_session FROM SessionsSuivies WHERE id_personne = ?";
        $sessionsIdAy = $app['db']->fetchAll($sql, [(int) $this->id]);
        $this->sessionsSuivies = [];
        foreach ($sessionsIdAy as $elmt) {
            $this->sessionsSuivies[] = $elmt["id_session"];
        }
        $sql = "SELECT id FROM Session WHERE id_enseignant = ?";
        $sessionsIdAy = $app['db']->fetchAll($sql, [(int) $this->id]);
        $this->sessionsMenees = [];
        foreach ($sessionsIdAy as $elmt) {
            $this->sessionsMenees[] = $elmt["id"];
        }
    }

    public function getJsonRepresentation() {
        $representation = [
            'id'               => $this->id,
            'nom'              => $this->nom,
            'prenom'           => $this->prenom,
            'sessions_suivies' => [],
            'sessions_menees'  => [],
        ];
        foreach ($this->sessionsSuivies as $session) {
            $representation['sessions_suivies'][] = $this->app['url_generator']->generate('api_r_session', ['session' => $session], 0);
        }
        foreach ($this->sessionsMenees as $session) {
            $representation['sessions_menees'][] = $this->app['url_generator']->generate('api_r_session', ['session' => $session], 0);
        }

        return (object) $representation;
    }

    public function save() {
        $sql = "INSERT INTO Personne(nom, prenom) VALUES (?, ?)";
        $this->app['db']->executeUpdate($sql, [$this->nom, $this->prenom]);
        $this->id = $this->app['db']->lastInsertId();
    }

    public function update() {
        $sql = "UPDATE Personne SET nom = ?, prenom = ? WHERE id = ?";
        $this->app['db']->executeUpdate($sql, [$this->nom, $this->prenom, $this->id]);
    }

    public function delete() {
        $sql = "DELETE from Personne WHER id = ?";
        $this->app['db']->executeUpdate($sql, [$this->id]);
    }

    public function updateSessionsSuivies() {
        $this->app['db']->executeUpdate("DELETE from SessionsSuivies WHERE id_personne = ?", [$this->id]);
        foreach ($this->sessionsSuivies as $idSession) {
            $this->app['db']->executeUpdate("INSERT INTO SessionsSuivies (id_personne, id_session) VALUES (?, ?)", [$this->id, $idSession]);
        }
    }
}

class Session extends Modele
{
    private $id;
    private $intitule;
    private $date;
    private $enseignant;
    private $stagiaires;

    /**
     * Session constructor.
     *
     * On ne saluera pas la présence d'une requête SQL
     *
     * @param $app
     */
    public function __construct($app)
    {
        parent::__construct($app);
        $sql = "SELECT id_personne FROM SessionsSuivies WHERE id_session = ?";
        $stagiairesIdAy = $app['db']->fetchAll($sql, [(int) $this->id]);
        $this->stagiaires = [];
        foreach ($stagiairesIdAy as $elmt) {
            $this->stagiaires[] = $elmt["id_personne"];
        }
        $enseignantId = $app['db']->executeQuery("SELECT Personne.id FROM Session INNER JOIN Personne ON Personne.id = Session.id_enseignant WHERE Session.id = ?", [$this->id])->fetch();
        $this->enseignant = Personne::load($app, $enseignantId);
    }

    public function getJsonRepresentation()
    {
        $representation = [
            'id'         => $this->id,
            'intitule'   => $this->intitule,
            'date'       => $this->date,
            'enseignant' => $this->app['url_generator']->generate('api_r_personne', ['personne' => $this->enseignant->id], 0),
            'stagiaires' => [],
        ];
        foreach ($this->stagiaires as $stagiaire) {
            $representation['stagiaires'][] = $this->app['url_generator']->generate('api_r_personne', ['personne' => $stagiaire], 0);
        }

        return (object) $representation;
    }

    public function save() {
        $sql = "INSERT INTO Session(intitule, date) VALUES (?, ?)";
        $this->app['db']->executeUpdate($sql, [$this->intitule, $this->date]);
        $this->id = $this->app['db']->lastInsertId();
    }

    public function update() {
        $sql = "UPDATE Session SET intitule = ?, date = ? WHERE id = ?";
        $this->app['db']->executeUpdate($sql, [$this->intitule, $this->date, $this->id]);
    }

    public function delete() {
        $sql = "DELETE from Session WHER id = ?";
        $this->app['db']->executeUpdate($sql, [$this->id]);
    }
}

/*
 * Et maintenant, nos routes...
 */

/*
 * On commence avec les personnes
 */
$app->get('/api/personnes/{personne}', function(Personne $personne) use ($app){
    return $app->json($personne->getJsonRepresentation());
})->convert('personne', $personneProvider)->bind('api_r_personne');

$app->get('/api/personnes', function() use ($app){
    $listePersonne = array_map(
        function($elmt) use ($app) {
            return $app['url_generator']->generate('api_r_personne', ['personne' => $elmt['id']], 0);
        },
        $app['db']->executeQuery("SELECT Personne.id FROM Personne")->fetchAll()
    );

    return $app->json($listePersonne);
})->bind('api_r_personnes');

$app->post('/api/personnes', function(\Symfony\Component\HttpFoundation\Request $requete) use ($app) {
    $personne = new Personne($app);
    $personne->nom = $requete->get('nom');
    $personne->prenom = $requete->get('prenom');
    $personne->save();
    return $app->json($app['url_generator']->generate('api_r_personne', ['personne' => $personne->id], 0), Symfony\Component\HttpFoundation\Response::HTTP_CREATED);
})->bind('api_p_personnes');

$app->put('/api/personnes/{personne}', function(\Symfony\Component\HttpFoundation\Request $requete, Personne $personne) use ($app) {
    try {
        if (!$personne) {
            return $app->json("Personne inconnue", Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }
        $personne->nom = $requete->request->get('nom');
        $personne->prenom = $requete->request->get('prenom');
        $personne->update();
        return $app->json($app['url_generator']->generate('api_r_personne', ['personne' => $personne->id], 0));
    } catch (Exception $e) {
        return $app->json("Something went wrong", Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);
    }
})->convert('personne', $personneProvider)->bind('api_u_personnes');

$app->delete('/api/personnes/{personne}', function(\Symfony\Component\HttpFoundation\Request $requete, Personne $personne) use ($app) {
    try {
        if (!$personne) {
            return $app->json("Personne inconnue", Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }
        $personne->nom = $requete->request->get('nom');
        $personne->prenom = $requete->request->get('prenom');
        $personne->update();
        return $app->json($app['url_generator']->generate('api_r_personne', ['personne' => $personne->id], 0));
    } catch (Exception $e) {
        return $app->json("Something went wrong", Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);
    }
})->convert('personne', $personneProvider)->bind('api_d_personnes');

$app->post('/api/personnes/{personne}/inscrire/{session}', function(Personne $personne, Session $session) use ($app) {
    $sessionsSuivies = $personne->sessionsSuivies;
    $sessionsSuivies[] = $session->id;
    $personne->sessionsSuivies = $sessionsSuivies;
    $personne->updateSessionsSuivies();
    return $app->json($app['url_generator']->generate('api_r_personne', ['personne' => $personne->id], 0));
})->convert('personne', $personneProvider)->convert('session', $sessionProvider)->bind('api_personne_session');

/*
 * Ici nous avons les sessions. C'est incomplet, c'est à vous de le compléter
 */
$app->get('/api/sessions/{session}', function(Session $session) use ($app){
    return $app->json($session->getJsonRepresentation());
})->convert('session', $sessionProvider)->bind('api_r_session');

$app->run();