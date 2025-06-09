<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordForm;
use App\Form\ResetPasswordRequestForm;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        private string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private string $fromName
    ) {}

    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, MailerInterface $mailer, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(ResetPasswordRequestForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            return $this->processSendingPasswordResetEmail($email, $mailer, $translator);
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        if (null === ($resetToken = $this->getTokenObjectFromSession())) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, TranslatorInterface $translator, ?string $token = null): Response
    {
        if ($token) {
            $this->storeTokenInSession($token);
            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->logger->warning('Reset password token validation failed', [
                'token' => $token,
                'error' => $e->getMessage(),
                'reason' => $e->getReason()
            ]);

            $this->addFlash('reset_password_error', sprintf(
                '%s - %s',
                $translator->trans(ResetPasswordExceptionInterface::MESSAGE_PROBLEM_VALIDATE, [], 'ResetPasswordBundle'),
                $translator->trans($e->getReason(), [], 'ResetPasswordBundle')
            ));

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $this->entityManager->flush();

            $this->cleanSessionAfterReset();

            $this->logger->info('Password successfully reset for user', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            $this->addFlash('success', 'Votre mot de passe a été modifié avec succès !');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form,
        ]);
    }

    private function processSendingPasswordResetEmail(string $emailFormData, MailerInterface $mailer, TranslatorInterface $translator): RedirectResponse
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $emailFormData,
        ]);

        if (!$user) {
            $this->logger->info('Password reset requested for non-existent email', [
                'email' => $emailFormData
            ]);
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->logger->error('Failed to generate reset token', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'error' => $e->getMessage()
            ]);

            return $this->redirectToRoute('app_check_email');
        }

        try {
            // Générer l'URL de réinitialisation
            $resetUrl = $this->generateUrl(
                'app_reset_password',
                ['token' => $resetToken->getToken()],
                \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Utiliser le même transport direct que TestEmailDirectController
            $dsn = 'gmail://hei.maria.625@gmail.com:nxsbwgwuskukqnsd@default';
            $transport = Transport::fromDsn($dsn);

            // Créer un email simple avec HTML intégré (comme TestEmailDirectController)
            $userName = $user->getFirstName() ?? $user->getEmail();
            
            $email = (new Email())
                ->from('hei.maria.625@gmail.com')
                ->to($user->getEmail())
                ->subject('🔐 Réinitialisation de votre mot de passe')
                ->text('Bonjour ' . $userName . ', cliquez sur ce lien pour réinitialiser votre mot de passe : ' . $resetUrl)
                ->html('
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                    <div style="background: #007bff; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;">
                        <h1>🔐 Réinitialisation de mot de passe</h1>
                    </div>
                    
                    <div style="padding: 20px; background: #f8f9fa; border-radius: 0 0 5px 5px;">
                        <p>Bonjour <strong>' . htmlspecialchars($userName) . '</strong>,</p>
                        
                        <p>Vous avez demandé la réinitialisation de votre mot de passe.</p>
                        
                        <p>Cliquez sur le bouton ci-dessous pour créer un nouveau mot de passe :</p>
                        
                        <p style="text-align: center;">
                            <a href="' . $resetUrl . '" style="display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0;">
                                Réinitialiser mon mot de passe
                            </a>
                        </p>
                        
                        <p><strong>Ce lien expire dans 1 heure.</strong></p>
                        
                        <p>Si vous n\'avez pas demandé cette réinitialisation, ignorez simplement cet email.</p>
                        
                        <p style="font-size: 12px; color: #666;">
                            Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>
                            <a href="' . $resetUrl . '">' . $resetUrl . '</a>
                        </p>
                    </div>
                    
                    <div style="padding: 20px; text-align: center; color: #666; font-size: 12px;">
                        <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
                        <p>© ' . date('Y') . ' Blog Symfony</p>
                    </div>
                </div>
            ');

            // Envoyer avec le transport direct (exactement comme TestEmailDirectController)
            $transport->send($email);

            $this->logger->info('Password reset email sent successfully with direct transport', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'token_expires_at' => $resetToken->getExpiresAt()->format('Y-m-d H:i:s'),
                'transport' => 'direct_gmail',
                'reset_url' => $resetUrl
            ]);

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send password reset email', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->addFlash('reset_password_error', 'Une erreur est survenue lors de l\'envoi de l\'email. Veuillez réessayer.');
            return $this->redirectToRoute('app_forgot_password_request');
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during password reset email sending', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->addFlash('reset_password_error', 'Une erreur inattendue est survenue. Veuillez réessayer.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        // Stocker le token en session pour la page de confirmation
        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}