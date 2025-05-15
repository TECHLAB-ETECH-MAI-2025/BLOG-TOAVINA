<?php
// src/Form/ArticleType.php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Article;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre de l\'article',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Entrez un titre pour votre article',
                    'required' => true
                ]
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu de l\'article',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 10,
                    'placeholder' => 'Écrivez le contenu de votre article ici...',
                    'required' => true
                ]
            ])
            ->add('categories', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'title',
                'multiple' => true,
                'expanded' => true,
                'label' => 'Catégories',
                'attr' => [
                    'class' => 'form-check',
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
        ]);
    }
}