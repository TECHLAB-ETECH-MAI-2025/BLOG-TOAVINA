<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

class DiagnosticResetPasswordController extends AbstractController
{
    #[Route('/diagnostic-reset-password', name: 'app_diagnostic_reset_password')]
    public function diagnostic(
        EntityManagerInterface $entityManager,
        ResetPasswordHelperInterface $resetPasswordHelper
    ): Response {
        $results = [];
        $email = 'hei.maria.625@gmail.com';

        // Test 1: Vérifier que l'utilisateur existe
        try {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $results['user_found'] = "✅ Utilisateur trouvé: ID=" . $user->getId();
            } else {
                $results['user_found'] = "❌ Utilisateur NON trouvé";
                return $this->renderResults($results);
            }
        } catch (\Exception $e) {
            $results['user_found'] = "❌ ERREUR: " . $e->getMessage();
            return $this->renderResults($results);
        }

        // Test 2: Générer le token
        try {
            $resetToken = $resetPasswordHelper->generateResetToken($user);
            $results['token_generation'] = "✅ Token généré: " . substr($resetToken->getToken(), 0, 20) . "...";
        } catch (\Exception $e) {
            $results['token_generation'] = "❌ ERREUR token: " . $e->getMessage();
            return $this->renderResults($results);
        }

        // Test 3: Créer le transport
        try {
            $dsn = 'gmail://hei.maria.625@gmail.com:nxsbwgwuskukqnsd@default';
            $transport = Transport::fromDsn($dsn);
            $results['transport_creation'] = "✅ Transport créé";
        } catch (\Exception $e) {
            $results['transport_creation'] = "❌ ERREUR transport: " . $e->getMessage();
            return $this->renderResults($results);
        }

        // Test 4: Créer l'email avec template
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('hei.maria.625@gmail.com', 'Blog Symfony'))
                ->to($user->getEmail())
                ->subject('🔐 Test Diagnostic - Réinitialisation')
                ->htmlTemplate('reset_password/email.html.twig')
                ->context([
                    'resetToken' => $resetToken,
                    'tokenLifetime' => $resetPasswordHelper->getTokenLifetime(),
                    'user' => $user
                ]);
            
            $results['email_creation'] = "✅ Email avec template créé";
        } catch (\Exception $e) {
            $results['email_creation'] = "❌ ERREUR email template: " . $e->getMessage();
            $results['email_error_details'] = "Type: " . get_class($e) . " | Ligne: " . $e->getLine() . " | Fichier: " . $e->getFile();
            
            // Test alternatif sans template
            try {
                $resetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $resetToken->getToken()],
                    \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
                );
                
                $emailSimple = (new \Symfony\Component\Mime\Email())
                    ->from('hei.maria.625@gmail.com')
                    ->to($user->getEmail())
                    ->subject('🔐 Test Diagnostic - Simple')
                    ->html('<h1>Test</h1><p>Lien: <a href="' . $resetUrl . '">Réinitialiser</a></p>');
                
                $results['email_simple_creation'] = "✅ Email simple créé (sans template)";
                $email = $emailSimple; // Utiliser l'email simple pour le test d'envoi
            } catch (\Exception $e2) {
                $results['email_simple_creation'] = "❌ ERREUR email simple: " . $e2->getMessage();
                return $this->renderResults($results);
            }
        }

        // Test 5: Envoyer l'email
        try {
            $transport->send($email);
            $results['email_sent'] = "✅ Email envoyé avec succès !";
        } catch (\Exception $e) {
            $results['email_sent'] = "❌ ERREUR envoi: " . $e->getMessage();
            $results['email_sent_details'] = "Type: " . get_class($e) . " | Ligne: " . $e->getLine();
        }

        // Test 6: Vérifier les logs Symfony
        try {
            $logFile = $this->getParameter('kernel.project_dir') . '/var/log/dev.log';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                $recentLogs = substr($logContent, -2000); // Derniers 2000 caractères
                $results['recent_logs'] = "✅ Logs récents disponibles";
                $results['log_preview'] = substr($recentLogs, 0, 500) . "...";
            } else {
                $results['recent_logs'] = "❌ Fichier de log non trouvé";
            }
        } catch (\Exception $e) {
            $results['recent_logs'] = "❌ ERREUR lecture logs: " . $e->getMessage();
        }

        return $this->renderResults($results);
    }

    private function renderResults(array $results): Response
    {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Diagnostic Reset Password</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 1000px; margin: 20px auto; padding: 20px; }
                h1 { color: #e53935; }
                .result { margin: 15px 0; padding: 15px; border-radius: 5px; }
                .success { background: #e8f5e9; border-left: 4px solid #4caf50; }
                .error { background: #ffebee; border-left: 4px solid #f44336; }
                .info { background: #e3f2fd; border-left: 4px solid #2196f3; }
                pre { background: #f5f5f5; padding: 10px; overflow: auto; font-size: 12px; }
                .error-details { font-size: 12px; color: #666; margin-top: 10px; }
            </style>
        </head>
        <body>
            <h1>🔍 Diagnostic Reset Password</h1>';

        foreach ($results as $key => $result) {
            $class = (strpos($result, '✅') !== false) ? 'success' : 
                    ((strpos($result, '❌') !== false) ? 'error' : 'info');
            
            $html .= '<div class="result ' . $class . '">';
            $html .= '<strong>' . ucfirst(str_replace('_', ' ', $key)) . ':</strong><br>';
            
            if ($key === 'log_preview') {
                $html .= '<pre>' . htmlspecialchars($result) . '</pre>';
            } else {
                $html .= htmlspecialchars($result);
            }
            
            $html .= '</div>';
        }

        $html .= '
            <div class="result info">
                <h3>🔧 Actions suivantes :</h3>
                <ol>
                    <li>Identifiez l\'étape qui échoue</li>
                    <li>Vérifiez les détails de l\'erreur</li>
                    <li>Consultez les logs Symfony pour plus d\'informations</li>
                    <li>Si le template échoue, utilisez un email simple</li>
                </ol>
            </div>
            
            <p><a href="/reset-password">🔄 Tester le vrai formulaire</a> | <a href="/test-email-direct">📧 Test email direct</a></p>
        </body>
        </html>';

        return new Response($html);
    }
}