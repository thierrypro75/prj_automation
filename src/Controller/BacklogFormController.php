<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BacklogFormController extends AbstractController
{
    #[Route('/', name: 'app_backlog_form', methods: ['GET'])]
    public function index(): Response
    {
        $technologies = [
            'PHP' => 'PHP',
            'Symfony' => 'Symfony',
            'WordPress' => 'WordPress',
            'Laravel' => 'Laravel',
            'React' => 'React',
            'Vue.js' => 'Vue.js',
            'Angular' => 'Angular',
            'Node.js' => 'Node.js',
            'Python' => 'Python',
            'Django' => 'Django',
        ];

        $niveaux = [
            'Débutant' => 'Débutant',
            'Intermédiaire' => 'Intermédiaire',
            'Expert' => 'Expert',
        ];

        return $this->render('backlog_form/index.html.twig', [
            'technologies' => $technologies,
            'niveaux' => $niveaux,
        ]);
    }
} 