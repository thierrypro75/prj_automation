<?php
// src/Controller/BacklogController.php

namespace App\Controller;

use OpenAI; // SDK OpenAI (ajouter via Composer)
use Smalot\PdfParser\Parser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;

class BacklogController extends AbstractController
{
    #[Route('/generate-backlog', methods: ['POST'])]
    public function generateBacklog(Request $request): JsonResponse
    {
        $cdcText = '';
        
        // Gérer le tech de manière plus sécurisée
        $techJson = $request->request->get('tech', '[]');
        $tech = json_decode($techJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Format des technologies invalide'], 400);
        }

        $niveauDev = $request->request->get('niveau_dev', '');

        /** @var UploadedFile|null $cdcFile */
        $cdcFile = $request->files->get('cdc');

        if ($cdcFile) {
            $pdfParser = new Parser();
            $pdf = $pdfParser->parseFile($cdcFile->getPathname());
            $cdcText = $pdf->getText();
        } else {
            $cdcText = $request->request->get('cdc', '');
        }

        if (empty($cdcText) || empty($tech) || empty($niveauDev)) {
            return new JsonResponse(['error' => 'Données incomplètes'], 400);
        }

        $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

        $prompt = "Tu es un expert en gestion de projet agile et en estimation des charges. " .
                  "Analyse le cahier des charges ci-dessous et génère un backlog détaillé en user stories selon la méthode SCRUM.\n\n" .
                  "# Cahier des charges :\n" . $cdcText . "\n\n" .
                  "# Technologies utilisées :\n" . implode(", ", $tech) . "\n\n" .
                  "# Niveau des développeurs :\n" . $niveauDev . "\n\n" .
                  "# Ce que je veux en sortie :\n" .
                  "- Une liste d'epics regroupant les user stories\n" .
                  "- Des user stories rédigées selon le format : 'En tant que [rôle], je veux [objectif] afin de [raison]'\n" .
                  "- Une estimation de complexité en points (1, 3, 5, 8, 13) pour chaque user story\n" .
                  "- Une estimation en jours/homme pour chaque user story en fonction du niveau des développeurs\n" .
                  "- Un résumé structuré et clair.\n\n" .
                  "Ne génère que du contenu structuré sans explications superflues.";

        $response = $client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.5
        ]);

        $backlog = $response['choices'][0]['message']['content'] ?? 'Erreur dans la génération';
        return new JsonResponse(['backlog' => $backlog]);
    }
}
