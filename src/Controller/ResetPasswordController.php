<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordForm;
use App\Form\ResetPasswordRequestForm;
use App\Service\GmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
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
        private GmailService $gmailService
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

            // Préparer les variables pour les templates
            $templateVars = [
                'resetToken' => $resetToken,
                'tokenLifetime' => $this->resetPasswordHelper->getTokenLifetime(),
                'user' => $user,
                'resetUrl' => $resetUrl
            ];

            // Rendre les templates Twig manuellement
            $htmlContent = $this->renderView('reset_password/email.html.twig', $templateVars);
            $textContent = $this->renderView('reset_password/email.txt.twig', $templateVars);

            // Utiliser le service Gmail avec ta configuration .env
            $emailSent = $this->gmailService->sendEmail(
                $user->getEmail(),
                '🔐 Réinitialisation de votre mot de passe',
                $textContent,
                $htmlContent
            );

            if ($emailSent) {
                $this->logger->info('Password reset email sent successfully via GmailService with Twig templates', [
                    'user_id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'token_expires_at' => $resetToken->getExpiresAt()->format('Y-m-d H:i:s'),
                    'reset_url' => $resetUrl,
                    'from_email' => $this->gmailService->getFromEmail(),
                    'from_name' => $this->gmailService->getFromName()
                ]);
            } else {
                $this->logger->error('Failed to send password reset email via GmailService', [
                    'user_id' => $user->getId(),
                    'email' => $user->getEmail()
                ]);

                $this->addFlash('reset_password_error', 'Une erreur est survenue lors de l\'envoi de l\'email. Veuillez réessayer.');
                return $this->redirectToRoute('app_forgot_password_request');
            }
        } catch (\Exception $e) {
            $this->logger->error('Error rendering templates or sending email', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->addFlash('reset_password_error', 'Une erreur est survenue lors de l\'envoi de l\'email. Veuillez réessayer.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        $this->setTokenObjectInSession($resetToken);
        return $this->redirectToRoute('app_check_email');
    }
}
