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
    private function analyserCDCAvecAI(string $cdcText, OpenAI\Client $client): string 
    {
        $prompt = "Tu es un expert en analyse de cahiers des charges. " .
                  "Analyse méticuleusement le cahier des charges ci-dessous et structure-le selon les sections suivantes. " .
                  "Pour chaque section, extrais et résume les informations pertinentes.\n\n" .
                  "Sections à analyser :\n" .
                  "1. Contexte et objectifs du projet\n" .
                  "2. Fonctionnalités principales demandées\n" .
                  "3. Contraintes techniques\n" .
                  "4. Exigences non fonctionnelles (performance, sécurité, etc.)\n" .
                  "5. Délais et jalons importants\n" .
                  "6. Critères de qualité et de réussite\n" .
                  "7. Risques potentiels identifiés\n\n" .
                  "Cahier des charges à analyser :\n" . $cdcText;

        $response = $client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'Tu es un expert en analyse de cahiers des charges. Ta mission est d\'extraire et structurer les informations essentielles.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.3
        ]);

        return $response['choices'][0]['message']['content'] ?? 'Erreur dans l\'analyse';
    }

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

        // Première étape : Analyse du CDC
        $analyseDetaillee = $this->analyserCDCAvecAI($cdcText, $client);

        $baseTime = match($niveauDev) {
            'Expert' => 0.0625,
            'Intermédiaire' => 0.125,
            'Débutant' => 1.0,
            default => 0.125
        };

        // Deuxième étape : Génération du backlog basée sur l'analyse
        $prompt = "Tu es un expert en gestion de projet agile et en estimation des charges. " .
                  "Utilise l'analyse détaillée du cahier des charges ci-dessous pour générer un backlog précis et cohérent.\n\n" .
                  "# Analyse détaillée du projet :\n" . $analyseDetaillee . "\n\n" .
                  "# Technologies imposées :\n" . implode(", ", $tech) . "\n\n" .
                  "# Niveau des développeurs :\n" . $niveauDev . "\n\n" .
                  "# Directives de génération :\n" .
                  "Format de sortie structuré en français :\n\n" .
                  "Utiliser exactement ce format pour chaque élément :\n\n" .
                  "EPIC: [Nom de l'Epic]\n" .
                  "FEATURE: [Nom de la Feature]\n" .
                  "STORY: [Description] | [points] | [jours/homme]\n\n" .
                  "Exemple de format attendu :\n" .
                  "EPIC: Infrastructure Technique\n" .
                  "FEATURE: Configuration Initiale\n" .
                  "STORY: En tant que développeur, je veux configurer l'environnement | 3 | 0.375\n\n" .
                  "Règles strictes à suivre :\n" .
                  "1. Chaque EPIC doit correspondre à un objectif majeur identifié dans l'analyse\n" .
                  "2. Chaque FEATURE doit répondre à un besoin fonctionnel explicite\n" .
                  "3. Chaque STORY doit être :\n" .
                  "   - Spécifique à une fonctionnalité\n" .
                  "   - Mesurable en termes d'effort\n" .
                  "   - Réalisable techniquement\n" .
                  "   - Pertinente pour le projet\n" .
                  "   - Limitée dans le temps\n\n" .
                  "4. Points de complexité : 1, 3, 5, 8, 13\n" .
                  "5. Temps en fonction du niveau :\n" .
                  "   - Expert : tâche simple (1 point) = 0.0625 j/h (30mn)\n" .
                  "   - Intermédiaire : tâche simple = 0.125 j/h (1h)\n" .
                  "   - Débutant : tâche simple = 1.0 j/h (1 jour)\n\n" .
                  "Activités techniques obligatoires à inclure :\n" .
                  "1. Développement :\n" .
                  "   - Mise en place initiale (environnement, dépendances)\n" .
                  "   - Configuration du projet\n" .
                  "   - Création des modèles de données\n" .
                  "   - Migrations de base de données\n" .
                  "   - Développement des API\n" .
                  "   - Développement des interfaces\n" .
                  "   - Tests unitaires\n" .
                  "   - Tests d'intégration\n" .
                  "2. Infrastructure :\n" .
                  "   - Configuration des serveurs\n" .
                  "   - Mise en place de l'intégration continue\n" .
                  "   - Configuration des sauvegardes\n" .
                  "   - Surveillance du système\n" .
                  "3. Référencement :\n" .
                  "   - Optimisation des métadonnées\n" .
                  "   - Structure des URLs\n" .
                  "   - Performance et vitesse\n" .
                  "4. Sécurité :\n" .
                  "   - Authentification\n" .
                  "   - Gestion des droits\n" .
                  "   - Protection des données\n" .
                  "   - Audit de sécurité\n" .
                  "5. Documentation :\n" .
                  "   - Documentation technique\n" .
                  "   - Guide utilisateur\n" .
                  "   - Documentation de l'API\n\n" .
                  "Contraintes supplémentaires :\n" .
                  "1. Respecter strictement les contraintes techniques mentionnées dans le CDC\n" .
                  "2. Inclure les aspects de qualité et de performance\n" .
                  "3. Prendre en compte les délais mentionnés\n" .
                  "4. Adapter les estimations aux technologies imposées\n" .
                  "5. Considérer les dépendances entre les tâches\n\n" .
                  
                  "Pour chaque Epic :\n" .
                  "1. Commencer par les fondations techniques\n" .
                  "2. Suivre une progression logique\n" .
                  "3. Inclure un sous-total en points et j/h\n\n" .
                  
                  "IMPORTANT :\n" .
                  "- Toute la sortie doit être en français\n" .
                  "- Les estimations doivent être réalistes et justifiées\n" .
                  "- Le backlog doit être exhaustif et cohérent\n" .
                  "- Temps minimum par tâche : " . $baseTime . " j/h (niveau " . $niveauDev . ")\n";

        $response = $client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'Tu es un expert en gestion de projet agile. Génère uniquement le contenu demandé sans texte additionnel.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.3
        ]);

        $backlog = $response['choices'][0]['message']['content'] ?? 'Erreur dans la génération';

        // Formater la réponse pour le front
        return new JsonResponse([
            'analyse' => $analyseDetaillee,
            'backlog' => $backlog
        ]);
    }
}
