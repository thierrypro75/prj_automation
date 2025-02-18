<?php
// src/Controller/BacklogController.php

namespace App\Controller;

use OpenAI; // SDK OpenAI (ajouter via Composer)
use Smalot\PdfParser\Parser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;
use Dompdf\Options;

class BacklogController extends AbstractController
{
    private function callGeminiAPI(string $prompt, string $seed = null): string
    {
        try {
            $apiKey = $_ENV['GEMINI_API_KEY'] ?? null;
            
            if (!$apiKey) {
                throw new \RuntimeException('La clé API Gemini n\'est pas configurée dans le fichier .env');
            }

            $url = 'https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent?key=' . $apiKey;
            
            // Générer un seed valide pour INT32 (entre -2147483648 et 2147483647)
            $seedValue = null;
            if ($seed) {
                $hash = hash('sha256', $seed);
                $seedValue = hexdec(substr($hash, 0, 8)) % 2147483647;
            }
            
            $data = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.0
                ]
            ];

            // N'ajouter le seed que s'il est défini
            if ($seedValue !== null) {
                $data['generationConfig']['seed'] = $seedValue;
            }

            // Activer le mode debug de cURL
            $verbose = fopen('php://temp', 'w+');
            
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Impossible d\'initialiser cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_VERBOSE => true,
                CURLOPT_STDERR => $verbose,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            
            // Récupérer les informations de debug
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);

            if ($response === false) {
                $error = curl_error($ch);
                $errno = curl_errno($ch);
                $info = curl_getinfo($ch);
                curl_close($ch);
                
                error_log("Erreur cURL détaillée: " . print_r([
                    'errno' => $errno,
                    'error' => $error,
                    'info' => $info,
                    'verbose_log' => $verboseLog
                ], true));

                throw new \RuntimeException(sprintf(
                    'Erreur cURL lors de l\'appel à Gemini API: [%d] %s. Info: %s',
                    $errno,
                    $error,
                    json_encode($info)
                ));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log("Réponse Gemini non-200: " . $response);
                $errorData = json_decode($response, true);
                $errorMessage = isset($errorData['error']) ? 
                    json_encode($errorData['error']) : 
                    'Erreur inconnue';
                
                throw new \RuntimeException(sprintf(
                    'Erreur HTTP %d lors de l\'appel à Gemini API: %s',
                    $httpCode,
                    $errorMessage
                ));
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Réponse Gemini invalide: " . $response);
                throw new \RuntimeException('Erreur lors du décodage de la réponse JSON: ' . json_last_error_msg());
            }

            if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Structure de réponse Gemini invalide: " . json_encode($result));
                throw new \RuntimeException('Format de réponse invalide de Gemini API');
            }

            return $result['candidates'][0]['content']['parts'][0]['text'];

        } catch (\Exception $e) {
            error_log('Erreur Gemini API: ' . $e->getMessage());
            
            try {
                $client = OpenAI::client($_ENV['OPENAI_API_KEY']);
                $response = $client->chat()->create([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        ['role' => 'system', 'content' => 'Tu es un expert en gestion de projet agile.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.0
                ]);
                
                return $response['choices'][0]['message']['content'] ?? 'Erreur dans la génération';
            } catch (\Exception $e2) {
                error_log('Erreur OpenAI (fallback): ' . $e2->getMessage());
                
                return sprintf(
                    "Une erreur est survenue lors de l'appel aux APIs :\n" .
                    "Gemini : %s\n" .
                    "OpenAI (fallback) : %s\n\n" .
                    "Veuillez vérifier :\n" .
                    "1. Que les clés API sont correctement configurées dans le fichier .env\n" .
                    "2. Que vous avez une connexion Internet stable\n" .
                    "3. Que les APIs sont disponibles\n\n" .
                    "Si le problème persiste, contactez l'administrateur système.",
                    $e->getMessage(),
                    $e2->getMessage()
                );
            }
        }
    }

    private function analyserCDCAvecAI(string $cdcText, OpenAI\Client $client, string $seed = null): string 
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

        try {
            $response = $client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'Tu es un expert en analyse de cahiers des charges. Ta mission est d\'extraire et structurer les informations essentielles.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.0, // Réduire la température à 0 pour plus de cohérence
                'seed' => $seed ? intval(substr(hash('sha256', $seed), 0, 8), 16) : null
            ]);

            return $response['choices'][0]['message']['content'] ?? 'Erreur dans l\'analyse';
        } catch (\Exception $e) {
            // En cas d'erreur avec OpenAI, on utilise Gemini
            return $this->callGeminiAPI($prompt, $seed);
        }
    }

    private function ensureUtf8($string): string
    {
        if (!mb_check_encoding($string, 'UTF-8') || !($string === mb_convert_encoding(mb_convert_encoding($string, 'UTF-32', 'UTF-8'), 'UTF-8', 'UTF-32'))) {
            $string = mb_convert_encoding($string, 'UTF-8', mb_detect_encoding($string));
        }
        return $string;
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

        // Créer un hash unique basé sur les paramètres d'entrée
        $paramHash = hash('sha256', json_encode([
            'cdc' => $cdcText,
            'tech' => $tech,
            'niveau' => $niveauDev
        ]));

        $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

        // Première étape : Analyse du CDC avec le hash comme seed
        $analyseDetaillee = $this->analyserCDCAvecAI($cdcText, $client, $paramHash);

        $baseTime = match($niveauDev) {
            'Expert' => 0.0625,
            'Intermédiaire' => 0.125,
            'Débutant' => 1.0,
            default => 0.125
        };

        // Deuxième étape : Génération du backlog basée sur l'analyse
        $prompt = "Tu es un expert en développement web et en gestion de projet agile. " .
                  "Analyse le cahier des charges ci-dessous et génère un backlog détaillé avec des user stories précises.\n\n" .
                  "# Analyse détaillée du projet :\n" . $analyseDetaillee . "\n\n" .
                  "# Technologies imposées :\n" . implode(", ", $tech) . "\n\n" .
                  "# Niveau des développeurs :\n" . $niveauDev . "\n\n" .
                  "# Directives de génération :\n" .
                  "Format de sortie structuré en français :\n\n" .
                  "EPIC: [Nom explicite de l'Epic]\n" .
                  "Description détaillée de l'epic et de son objectif global\n\n" .
                  "FEATURE: [Nom explicite de la Feature]\n" .
                  "Description détaillée de la feature et de ses objectifs spécifiques\n" .
                  "STORY: En tant que [type d'utilisateur], je veux [action/objectif] afin de [bénéfice/valeur] | [points] | [jours/homme]\n\n" .
                  
                  "Structure à suivre pour chaque fonctionnalité du CDC :\n\n" .
                  
                  "1. Pages et Navigation :\n" .
                  "   - Page d'accueil et sa structure\n" .
                  "   - Menu de navigation\n" .
                  "   - Pages de contenu\n" .
                  "   - Pied de page\n" .
                  "   Exemple de story :\n" .
                  "   STORY: En tant que visiteur, je veux avoir un menu de navigation clair et accessible afin de facilement trouver les informations que je cherche | 3 | 0.375\n\n" .
                  
                  "2. Contenu et Présentation :\n" .
                  "   - Sections de contenu\n" .
                  "   - Galeries d'images\n" .
                  "   - Mise en page responsive\n" .
                  "   - Animations et transitions\n" .
                  "   Exemple de story :\n" .
                  "   STORY: En tant qu'administrateur, je veux pouvoir gérer les images de la galerie afin de maintenir le contenu à jour | 5 | 0.625\n\n" .
                  
                  "3. Interactions Utilisateur :\n" .
                  "   - Formulaires\n" .
                  "   - Validations\n" .
                  "   - Messages de confirmation\n" .
                  "   - Feedback utilisateur\n" .
                  "   Exemple de story :\n" .
                  "   STORY: En tant que visiteur, je veux recevoir une confirmation après l'envoi du formulaire de contact | 3 | 0.375\n\n" .
                  
                  "4. Administration et Gestion :\n" .
                  "   - Interface admin\n" .
                  "   - Gestion des contenus\n" .
                  "   - Tableau de bord\n" .
                  "   - Statistiques\n" .
                  "   Exemple de story :\n" .
                  "   STORY: En tant qu'administrateur, je veux avoir un tableau de bord pour visualiser les statistiques de visite | 5 | 0.625\n\n" .
                  
                  "Points de complexité : 1, 3, 5, 8, 13\n" .
                  "Temps en fonction du niveau :\n" .
                  "- Expert : tâche simple (1 point) = 0.0625 j/h (30mn)\n" .
                  "- Intermédiaire : tâche simple = 0.125 j/h (1h)\n" .
                  "- Débutant : tâche simple = 1.0 j/h (1 jour)\n\n" .
                  
                  "IMPORTANT :\n" .
                  "- Chaque EPIC doit avoir une description claire de son objectif\n" .
                  "- Chaque FEATURE doit expliquer précisément ce qu'elle apporte\n" .
                  "- Les STORIES doivent suivre strictement le format 'En tant que... je veux... afin de...'\n" .
                  "- Se baser UNIQUEMENT sur les fonctionnalités demandées dans le CDC\n" .
                  "- Adapter les tâches aux technologies imposées : " . implode(", ", $tech) . "\n" .
                  "- Chaque story doit être suffisamment détaillée pour être développée\n" .
                  "- Inclure les aspects responsive et SEO si mentionnés dans le CDC\n" .
                  "- Temps minimum par tâche : " . $baseTime . " j/h (niveau " . $niveauDev . ")\n";

        try {
            $response = $client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'Tu es un expert en gestion de projet agile. Génère uniquement le contenu demandé sans texte additionnel.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.3
            ]);

            $backlog = $response['choices'][0]['message']['content'] ?? 'Erreur dans la génération';
        } catch (\Exception $e) {
            // En cas d'erreur avec OpenAI, on utilise Gemini
            $backlog = $this->callGeminiAPI($prompt, $paramHash);
        }

        // Formater la réponse pour le front
        return new JsonResponse([
            'analyse' => $this->ensureUtf8($analyseDetaillee),
            'backlog' => $this->ensureUtf8($backlog)
        ], 200, ['Content-Type' => 'application/json;charset=UTF-8']);
    }

    #[Route('/export-backlog/{format}', methods: ['POST'])]
    public function exportBacklog(Request $request, string $format): Response
    {
        $data = json_decode($request->getContent(), true);
        $analyse = $data['analyse'] ?? '';
        $backlog = $data['backlog'] ?? '';

        if ($format === 'pdf') {
            return $this->exportToPdf($analyse, $backlog);
        } elseif ($format === 'excel') {
            return $this->exportToExcel($analyse, $backlog);
        }

        return new JsonResponse(['error' => 'Format non supporté'], 400);
    }

    private function exportToPdf(string $analyse, string $backlog): Response
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);

        $dompdf = new Dompdf($options);
        
        $html = $this->renderView('backlog_form/export_pdf.html.twig', [
            'analyse' => $analyse,
            'backlog' => $backlog,
            'date' => new \DateTime()
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $response = new Response($dompdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="backlog_' . date('Y-m-d_His') . '.pdf"');

        return $response;
    }

    private function exportToExcel(string $analyse, string $backlog): Response
    {
        $spreadsheet = new Spreadsheet();
        
        // Onglet Analyse
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Analyse CDC');
        $sheet->setCellValue('A1', 'Analyse du Cahier des Charges');
        $sheet->setCellValue('A2', $analyse);
        
        // Onglet Backlog
        $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex(1);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Backlog');
        
        // En-têtes
        $sheet->setCellValue('A1', 'Type');
        $sheet->setCellValue('B1', 'Epic/Feature');
        $sheet->setCellValue('C1', 'Description');
        $sheet->setCellValue('D1', 'Points');
        $sheet->setCellValue('E1', 'Jours/Homme');

        // Traitement du backlog
        $lines = explode("\n", $backlog);
        $row = 2;
        $currentEpic = '';
        
        foreach ($lines as $line) {
            if (strpos($line, 'EPIC:') !== false) {
                $currentEpic = trim(str_replace('EPIC:', '', $line));
                $sheet->setCellValue('A' . $row, 'EPIC');
                $sheet->setCellValue('B' . $row, $currentEpic);
                $row++;
            } 
            elseif (strpos($line, 'FEATURE:') !== false) {
                $feature = trim(str_replace('FEATURE:', '', $line));
                $sheet->setCellValue('A' . $row, 'FEATURE');
                $sheet->setCellValue('B' . $row, $currentEpic);
                $sheet->setCellValue('C' . $row, $feature);
                $row++;
            }
            elseif (strpos($line, 'STORY:') !== false) {
                $storyParts = explode('|', str_replace('STORY:', '', $line));
                if (count($storyParts) === 3) {
                    $sheet->setCellValue('A' . $row, 'STORY');
                    $sheet->setCellValue('B' . $row, $currentEpic);
                    $sheet->setCellValue('C' . $row, trim($storyParts[0]));
                    $sheet->setCellValue('D' . $row, trim($storyParts[1]));
                    $sheet->setCellValue('E' . $row, trim($storyParts[2]));
                    $row++;
                }
            }
        }

        // Ajustement automatique des colonnes
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Création du fichier Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'backlog_' . date('Y-m-d_His') . '.xlsx';
        
        $tempFile = tempnam(sys_get_temp_dir(), 'backlog');
        $writer->save($tempFile);
        
        $response = new Response(file_get_contents($tempFile));
        unlink($tempFile);
        
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');

        return $response;
    }
}
