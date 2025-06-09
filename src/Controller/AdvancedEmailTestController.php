<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

class AdvancedEmailTestController extends AbstractController
{
    #[Route('/advanced-email-test', name: 'app_advanced_email_test')]
    public function index(
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
        ResetPasswordHelperInterface $resetPasswordHelper
    ): Response {
        $results = [];
        $testEmail = 'hei.maria.625@gmail.com';
        
        // Test 1: Email simple avec transport par défaut
        try {
            $email1 = (new Email())
                ->from('no-reply-test@example.com') // Adresse différente
                ->to($testEmail)
                ->subject('Test 1: Email simple - ' . uniqid())
                ->text('Ceci est un test simple')
                ->html('<h1>Test 1</h1><p>Email simple envoyé à ' . date('H:i:s') . '</p>');
            
            $mailer->send($email1);
            $results['test1'] = '✅ Email simple envoyé';
        } catch (\Exception $e) {
            $results['test1'] = '❌ Erreur: ' . $e->getMessage();
        }
        
        // Test 2: Email avec transport Gmail direct
        try {
            $dsn = 'gmail://hei.maria.625@gmail.com:nxsbwgwuskukqnsd@default';
            $transport = Transport::fromDsn($dsn);
            
            $email2 = (new Email())
                ->from('hei.maria.625@gmail.com')
                ->to('raholiniainarotsy@gmail.com') // Adresse différente
                ->subject('Test 2: Email vers autre adresse - ' . uniqid())
                ->text('Test vers une autre adresse')
                ->html('<h1>Test 2</h1><p>Email vers autre adresse à ' . date('H:i:s') . '</p>');
            
            $transport->send($email2);
            $results['test2'] = '✅ Email vers autre adresse envoyé';
        } catch (\Exception $e) {
            $results['test2'] = '❌ Erreur: ' . $e->getMessage();
        }
        
        // Test 3: Email avec template Twig
        try {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $testEmail]);
            
            if (!$user) {
                $results['test3'] = '❌ Utilisateur non trouvé';
            } else {
                $resetToken = $resetPasswordHelper->generateResetToken($user);
                
                $email3 = (new TemplatedEmail())
                    ->from(new Address('test-sender@example.com', 'Test Sender')) // Adresse différente
                    ->to($testEmail)
                    ->subject('Test 3: Email avec template - ' . uniqid())
                    ->htmlTemplate('reset_password/email.html.twig')
                    ->context([
                        'resetToken' => $resetToken,
                        'tokenLifetime' => $resetPasswordHelper->getTokenLifetime(),
                        'user' => $user
                    ]);
                
                $mailer->send($email3);
                $results['test3'] = '✅ Email avec template envoyé';
            }
        } catch (\Exception $e) {
            $results['test3'] = '❌ Erreur: ' . $e->getMessage();
        }
        
        // Test 4: Email avec lien direct (sans template)
        try {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $testEmail]);
            
            if (!$user) {
                $results['test4'] = '❌ Utilisateur non trouvé';
            } else {
                $resetToken = $resetPasswordHelper->generateResetToken($user);
                
                $resetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $resetToken->getToken()],
                    \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
                );
                
                $email4 = (new Email())
                    ->from(new Address('another-sender@example.com', 'Another Sender')) // Adresse différente
                    ->to($testEmail)
                    ->subject('Test 4: Email avec lien direct - ' . uniqid())
                    ->html('
                        <h1>Test 4: Lien direct</h1>
                        <p>Voici votre lien de réinitialisation:</p>
                        <p><a href="' . $resetUrl . '">Réinitialiser le mot de passe</a></p>
                        <p>URL: ' . $resetUrl . '</p>
                    ');
                
                $mailer->send($email4);
                $results['test4'] = '✅ Email avec lien direct envoyé';
            }
        } catch (\Exception $e) {
            $results['test4'] = '❌ Erreur: ' . $e->getMessage();
        }
        
        // Générer la réponse HTML
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Tests Email Avancés</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; }
                h1 { color: #e53935; }
                .test { margin: 15px 0; padding: 15px; border-radius: 5px; }
                .success { background: #e8f5e9; border-left: 4px solid #4caf50; }
                .error { background: #ffebee; border-left: 4px solid #f44336; }
                .info { background: #e3f2fd; border-left: 4px solid #2196f3; }
            </style>
        </head>
        <body>
            <h1>🧪 Tests Email Avancés</h1>';
        
        foreach ($results as $key => $result) {
            $class = (strpos($result, '✅') !== false) ? 'success' : 'error';
            $html .= '<div class="test ' . $class . '"><strong>' . $key . ':</strong> ' . $result . '</div>';
        }
        
        $html .= '
            <div class="test info">
                <h3>🔍 Vérifications importantes:</h3>
                <ol>
                    <li>Vérifiez <strong>tous</strong> les dossiers Gmail (Spam, Promotions, etc.)</li>
                    <li>Attendez 2-3 minutes pour la livraison</li>
                    <li>Vérifiez si certains tests fonctionnent et d\'autres non</li>
                    <li>Si aucun email n\'arrive, le problème est probablement lié à Gmail</li>
                </ol>
            </div>
            
            <div class="test info">
                <h3>🔧 Solutions possibles:</h3>
                <ol>
                    <li>Utilisez une adresse d\'expéditeur différente</li>
                    <li>Utilisez Mailtrap pour le développement</li>
                    <li>Configurez SPF/DKIM pour votre domaine</li>
                    <li>Utilisez un service d\'email professionnel comme SendGrid ou Mailgun</li>
                </ol>
            </div>
            
            <p><a href="/test-reset-password-flow">🔄 Test Reset Password</a> | <a href="/">🏠 Accueil</a></p>
        </body>
        </html>';
        
        return new Response($html);
    }
}