<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\ArticleLike;
use App\Form\ArticleForm;
use App\Form\CommentFormType;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/article')]
final class ArticleController extends AbstractController
{
    #[Route(name: 'app_article_index', methods: ['GET'])]
    public function index(
        ArticleRepository $articleRepository,
        Request $request,
        PaginatorInterface $paginator
    ): Response {
        // Requête pour récupérer tous les articles
        $query = $articleRepository->createQueryBuilder('a')
            ->orderBy('a.id', 'ASC') // Tri par ID croissant
            ->getQuery();

        // Pagination avec 5 articles par page
        $articles = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1), // Page actuelle
            5 // Limite à 5 articles par page
        );

        return $this->render('article/index.html.twig', [
            'articles' => $articles,
        ]);
    }

    #[Route('/blog', name: 'app_blog_home')]
    public function blog(
        ArticleRepository $articleRepository,
        Request $request,
        EntityManagerInterface $em,
        PaginatorInterface $paginator
    ): Response {
        $query = $articleRepository->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'ASC') // Tri par date croissante
            ->getQuery();

        $articles = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            5 // 5 articles par page aussi
        );

        $commentForms = [];

        foreach ($articles as $article) {
            $comment = new Comment();
            $comment->setArticle($article);

            $form = $this->createForm(CommentFormType::class, $comment);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $comment->setArticle($article);
                $comment->setCreatedAt(new \DateTime());
                try {
                    $em->persist($comment);
                    $em->flush();
                } catch (\Exception $e) {
                    dd($e->getMessage());
                }
                return $this->redirectToRoute('app_blog_home');
            }

            $commentForms[$article->getId()] = $form->createView();
        }

        return $this->render('blog/index.html.twig', [
            'articles' => $articles,
            'commentForms' => $commentForms,
        ]);
    }

    #[Route('/new', name: 'app_article_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $article = new Article();
        $form = $this->createForm(ArticleForm::class, $article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($article);
            $entityManager->flush();

            return $this->redirectToRoute('app_article_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('article/new.html.twig', [
            'article' => $article,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_article_show', methods: ['GET', 'POST'])]
    public function show(
        Article $article,
        Request $request,
        EntityManagerInterface $em,
        ArticleRepository $articleRepository
    ): Response {
        $comment = new Comment();
        $form = $this->createForm(CommentFormType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setArticle($article);
            try {
                $em->persist($comment);
                $em->flush();
            } catch (\Exception $e) {
                dd($e->getMessage());
            }

            return $this->redirectToRoute('app_article_show', ['id' => $article->getId()]);
        }

        // Récupérer l'article précédent et suivant (ordre croissant)
        $previousArticle = $articleRepository->createQueryBuilder('a')
            ->where('a.id < :currentId')
            ->setParameter('currentId', $article->getId())
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $nextArticle = $articleRepository->createQueryBuilder('a')
            ->where('a.id > :currentId')
            ->setParameter('currentId', $article->getId())
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        // Vérifier si l'utilisateur a aimé l'article
        $userLiked = false;
        $userIp = $request->getClientIp();
        foreach ($article->getLikes() as $like) {
            if ($like->getIpAddress() === $userIp) {
                $userLiked = true;
                break;
            }
        }

        return $this->render('article/show.html.twig', [
            'article' => $article,
            'commentForm' => $form->createView(),
            'previousArticle' => $previousArticle,
            'nextArticle' => $nextArticle,
            'userLiked' => $userLiked,
        ]);
    }

    #[Route('/{id}/like', name: 'article_like', methods: ['POST'])]
    public function like(Article $article, Request $request, EntityManagerInterface $em): Response
    {
        $ip = $request->getClientIp();

        $existingLike = null;
        foreach ($article->getLikes() as $like) {
            if ($like->getIpAddress() === $ip) {
                $existingLike = $like;
                break;
            }
        }

        if ($existingLike) {
            $em->remove($existingLike);
            $em->flush();
            $this->addFlash('success', 'Vous avez retiré votre like.');
        } else {
            $like = new ArticleLike();
            $like->setIpAddress($ip);
            $like->setCreatedAt(new \DateTimeImmutable());
            $like->setArticle($article);

            $em->persist($like);
            $em->flush();
            $this->addFlash('success', 'Merci pour votre like !');
        }

        return $this->redirectToRoute('app_article_show', ['id' => $article->getId()]);
    }

    #[Route('/{id}/edit', name: 'app_article_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Article $article, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ArticleForm::class, $article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_article_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('article/edit.html.twig', [
            'article' => $article,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_article_delete', methods: ['POST'])]
    public function delete(Request $request, Article $article, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $article->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($article);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_article_index', [], Response::HTTP_SEE_OTHER);
    }
}
