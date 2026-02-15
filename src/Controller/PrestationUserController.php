<?php

namespace App\Controller;

use App\Entity\Prestation;
use App\Enum\StatutPrestation;
use App\Repository\PrestationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Dompdf\Dompdf;
use Dompdf\Options;

#[Route('/mes-prestations')]
#[IsGranted('ROLE_USER')]
class PrestationUserController extends AbstractController
{
    #[Route('/', name: 'app_user_prestations')]
    public function index(Request $request, PrestationRepository $repo): Response
    {
        $user = $this->getUser();
        $date = $request->query->get('date') ? new \DateTimeImmutable($request->query->get('date')) : new \DateTimeImmutable('today');

        $prestations = $repo->createQueryBuilder('p')
            ->andWhere('p.employe = :user')
            ->andWhere('p.datePrestation BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $date->setTime(0, 0))
            ->setParameter('end', $date->setTime(23, 59, 59))
            ->orderBy('p.datePrestation', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('prestation_user/index.html.twig', [
            'prestations' => $prestations,
            'selectedDate' => $date,
        ]);
    }

    #[Route('/{id}', name: 'app_user_prestation_view')]
    public function view(Prestation $prestation, Request $request, EntityManagerInterface $em): Response {
        if ($prestation->getEmploye() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $editMode = $request->query->getBoolean('edit');

        if ($request->isMethod('POST')) {
            $compteRendu = $request->request->get('compte_rendu');
            $signature = $request->request->get('signature');

            $prestation->setCompteRendu($compteRendu);

            // Champs personnalisés
            $valeursJson = $request->request->get('valeurs_champs_personnalises');
            if ($valeursJson) {
                $valeurs = json_decode($valeursJson, true);
                if (is_array($valeurs)) {
                    $sanitized = [];
                    foreach ($valeurs as $v) {
                        $sanitized[] = [
                            'label' => $v['label'] ?? '',
                            'type' => $v['type'] ?? 'checkbox',
                            'checked' => (bool) ($v['checked'] ?? false),
                            'value' => isset($v['value']) && $v['value'] !== null ? (int) $v['value'] : null,
                        ];
                    }
                    $prestation->setValeursChampsPersonnalises($sanitized);
                }
            }

            // Infos intervention (présence, infestation, propreté, encombrement)
            $prestation->setInfosIntervention([
                'presenceConstatee' => $request->request->get('presence_constatee'),
                'niveauInfestation' => $request->request->get('niveau_infestation'),
                'logementPropre' => $request->request->get('logement_propre'),
                'logementEncombre' => $request->request->get('logement_encombre'),
            ]);

            if ($signature) {
                $prestation->setSignature($signature);
            }

            // Si c'est une validation (pas encore terminé), on termine aussi
            if ($request->request->has('valider') && $prestation->getStatut() !== StatutPrestation::TERMINE) {
                $prestation->setStatut(StatutPrestation::TERMINE);
            }

            $em->flush();

            $message = $request->request->has('valider') ? 'Prestation validée avec succès !' : 'Prestation mise à jour.';
            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_user_prestation_view', [
                'id' => $prestation->getId()
            ]);
        }

        return $this->render('prestation_user/view.html.twig', [
            'prestation' => $prestation,
            'editMode' => $editMode,
        ]);
    }

#[Route('/{id}/terminer', name: 'app_user_prestation_terminer', methods: ['POST'])]
public function terminer(Prestation $prestation, EntityManagerInterface $em): Response
{
    $this->denyAccessUnlessGranted('ROLE_USER');

    $prestation->setStatut(StatutPrestation::TERMINE);
    $em->flush();

    $this->addFlash('success', 'Prestation terminée avec succès !');

    // Rediriger vers la même page avec l'aperçu PDF
    return $this->redirectToRoute('app_user_prestation_view', ['id' => $prestation->getId()]);
}

    #[Route('/prestation/{id}/pdf', name: 'prestation_pdf')]
    public function pdf(Prestation $prestation): Response
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('isPhpEnabled', true);

        $dompdf = new Dompdf($options);

        // Passer le chemin absolu de l'image
        $logoPath = $this->getParameter('kernel.project_dir') . '/public/images/logo.png';
        $logoBase64 = '';
        
        if (file_exists($logoPath)) {
            $logoData = file_get_contents($logoPath);
            $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
        }

        $html = $this->renderView('prestation_user/pdf.html.twig', [
            'prestation' => $prestation,
            'logo_base64' => $logoBase64,
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $pdfOutput = $dompdf->output();

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="prestation-'.$prestation->getId().'.pdf"');

        return $response;
    }


}   
