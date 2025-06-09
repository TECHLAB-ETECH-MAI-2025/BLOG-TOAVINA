<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    #[Route('', name: 'app_profile')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/edit', name: 'app_profile_edit')]
    public function edit(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->flush();

                $this->logger->info('User profile updated successfully', [
                    'user_id' => $user->getId(),
                    'email' => $user->getEmail()
                ]);

                $this->addFlash('success', 'Votre profil a été mis à jour avec succès.');

                return $this->redirectToRoute('app_profile');
            } catch (\Exception $e) {
                $this->logger->error('Error updating user profile', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);

                $this->addFlash('error', 'Une erreur est survenue lors de la mise à jour de votre profil.');
            }
        }

        return $this->render('profile/edit.html.twig', [
            'profileForm' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/change-password', name: 'app_profile_change_password')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Vérifier l'ancien mot de passe
            $currentPassword = $form->get('currentPassword')->getData();
            if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->logger->warning('Invalid current password attempt', [
                    'user_id' => $user->getId(),
                    'email' => $user->getEmail()
                ]);

                $this->addFlash('error', 'Le mot de passe actuel est incorrect.');
                return $this->redirectToRoute('app_profile_change_password');
            }

            try {
                // Encoder le nouveau mot de passe
                $newPassword = $form->get('plainPassword')->getData();
                $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
                $user->setPassword($hashedPassword);

                $entityManager->flush();

                $this->logger->info('User password changed successfully', [
                    'user_id' => $user->getId(),
                    'email' => $user->getEmail()
                ]);

                $this->addFlash('success', 'Votre mot de passe a été modifié avec succès.');

                return $this->redirectToRoute('app_profile');
            } catch (\Exception $e) {
                $this->logger->error('Error changing user password', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);

                $this->addFlash('error', 'Une erreur est survenue lors du changement de mot de passe.');
            }
        }

        return $this->render('profile/change_password.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/delete', name: 'app_profile_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Vérifier le token CSRF
        if (!$this->isCsrfTokenValid('delete_profile', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_profile');
        }

        // Vérifier le mot de passe pour confirmation
        $password = $request->request->get('password');
        if (!$passwordHasher->isPasswordValid($user, $password)) {
            $this->addFlash('error', 'Mot de passe incorrect.');
            return $this->redirectToRoute('app_profile');
        }

        try {
            $this->logger->info('User account deleted', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            $entityManager->remove($user);
            $entityManager->flush();

            // Déconnecter l'utilisateur
            $request->getSession()->invalidate();

            $this->addFlash('success', 'Votre compte a été supprimé avec succès.');

            return $this->redirectToRoute('app_home');
        } catch (\Exception $e) {
            $this->logger->error('Error deleting user account', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            $this->addFlash('error', 'Une erreur est survenue lors de la suppression du compte.');
            return $this->redirectToRoute('app_profile');
        }
    }
}
