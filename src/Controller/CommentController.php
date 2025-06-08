<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Form\CommentForm;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/comment')]
final class CommentController extends AbstractController
{
    #[Route(name: 'app_comment_index', methods: ['GET'])]
    public function index(
        CommentRepository $commentRepository,
        Request $request,
        PaginatorInterface $paginator
    ): Response {
        // Créer une requête pour récupérer tous les commentaires
        $query = $commentRepository->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC') // Tri par date décroissante (plus récents d'abord)
            ->getQuery();

        // Pagination avec 5 commentaires par page
        $comments = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1), // Page actuelle
            5 // Limite à 5 commentaires par page
        );

        return $this->render('comment/index.html.twig', [
            'comments' => $comments,
        ]);
    }

    #[Route('/new', name: 'app_comment_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $comment = new Comment();
        $form = $this->createForm(CommentForm::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setCreatedAt(new \DateTime());
            $entityManager->persist($comment);
            $entityManager->flush();

            return $this->redirectToRoute('app_comment_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('comment/new.html.twig', [
            'comment' => $comment,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_comment_show', methods: ['GET'])]
    public function show(
        Comment $comment,
        CommentRepository $commentRepository
    ): Response {
        // Récupérer le commentaire précédent et suivant (ordre par date décroissante)
        $previousComment = $commentRepository->createQueryBuilder('c')
            ->where('c.createdAt < :currentDate')
            ->setParameter('currentDate', $comment->getCreatedAt())
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $nextComment = $commentRepository->createQueryBuilder('c')
            ->where('c.createdAt > :currentDate')
            ->setParameter('currentDate', $comment->getCreatedAt())
            ->orderBy('c.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->render('comment/show.html.twig', [
            'comment' => $comment,
            'previousComment' => $previousComment,
            'nextComment' => $nextComment,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_comment_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Comment $comment, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CommentForm::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_comment_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('comment/edit.html.twig', [
            'comment' => $comment,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_comment_delete', methods: ['POST'])]
    public function delete(Request $request, Comment $comment, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $comment->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($comment);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_comment_index', [], Response::HTTP_SEE_OTHER);
    }
}
