# SmartAuth API - Convention de nommage

## Objectif

Normaliser l'exposition des objets Dolibarr vers l'API pour offrir une interface cohérente, en anglais, et optimisée pour les applications clientes (mobile, web).

## Principes généraux

1. **Langue** : Tous les noms de champs sont en anglais
2. **Format** : snake_case pour tous les champs
3. **Clarté** : Noms explicites, pas d'abréviations cryptiques
4. **Efficacité** : Minimiser les requêtes API côté client

---

## Foreign Keys vers objets métier

Pour les FK vers des objets métier (Societe, Project, Contact...), on expose **les deux** : l'ID et l'objet embarqué.

| Dolibarr | Front (ID) | Front (Object) |
|----------|------------|----------------|
| `fk_soc` | `thirdparty_id` | `thirdparty` |
| `fk_projet` | `project_id` | `project` |
| `fk_contrat` | `contract_id` | `contract` |
| `fk_contact` | `contact_id` | `contact` |
| `fk_user_creat` | `created_by_id` | `created_by` |
| `fk_user_modif` | `updated_by_id` | `updated_by` |
| `fk_user_valid` | `validated_by_id` | `validated_by` |

### Exemple de réponse

```json
{
  "id": 123,
  "ref": "FI2024-0042",
  "thirdparty_id": 456,
  "thirdparty": {
    "id": 456,
    "name": "ACME Corp",
    "city": "Paris",
    "country": "France"
  },
  "project_id": 789,
  "project": {
    "id": 789,
    "ref": "PRJ-2024-001",
    "title": "Migration ERP"
  }
}
```

### Utilisation côté client

- **Affichage** : utiliser l'objet embarqué (`thirdparty.name`)
- **Modification/Lien** : utiliser l'ID (`thirdparty_id`)

---

## Foreign Keys vers dictionnaires

Pour les FK vers des tables dictionnaires (pays, civilité, statuts...), on expose **l'objet avec code et label**.

| Dolibarr | Front |
|----------|-------|
| `fk_pays` | `country` |
| `fk_departement` | `state` |
| `fk_c_civility` | `civility` |
| `fk_c_typent` | `company_type` |
| `fk_c_stcomm` | `commercial_status` |

### Format des dictionnaires

```json
{
  "country": {
    "code": "FR",
    "label": "France"
  },
  "state": {
    "code": "69",
    "label": "Rhône"
  },
  "civility": {
    "code": "MR",
    "label": "Monsieur"
  }
}
```

> **Note** : Le code est essentiel pour les formulaires (select), le label pour l'affichage.

---

## Champs standards

### Identifiants

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `rowid` | `id` | Identifiant unique |
| `ref` | `ref` | Référence métier |
| `ref_ext` | `external_ref` | Référence externe |
| `ref_client` | `customer_ref` | Référence client |
| `ref_supplier` | `supplier_ref` | Référence fournisseur |

### Dénominations

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `nom` | `name` | Nom (société) |
| `lastname` | `lastname` | Nom de famille |
| `firstname` | `firstname` | Prénom |
| `label` | `label` | Libellé |
| `title` | `title` | Titre |
| `description` | `description` | Description |

### Adresse

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `address` | `address` | Adresse |
| `zip` | `zip` | Code postal |
| `town` | `city` | Ville |
| `fk_departement` | `state` | Département/État |
| `fk_pays` | `country` | Pays |

### Contact

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `phone` | `phone` | Téléphone fixe |
| `phone_mobile` | `mobile` | Téléphone mobile |
| `fax` | `fax` | Fax |
| `email` | `email` | Email |
| `url` | `website` | Site web |

### Dates

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `datec` | `created_at` | Date de création |
| `date_creation` | `created_at` | Date de création |
| `tms` | `updated_at` | Date de modification |
| `date_valid` | `validated_at` | Date de validation |
| `dateo` | `date_start` | Date de début |
| `datee` | `date_end` | Date de fin |
| `datei` | `date_intervention` | Date d'intervention |
| `date_contrat` | `date_contract` | Date du contrat |

### Notes

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `note_public` | `public_note` | Note publique |
| `note_private` | `private_note` | Note privée |

### Statut

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `status` | `status` | Code statut (int) |
| `statut` | `status` | Code statut (int) |
| - | `status_label` | Libellé du statut (calculé) |

---

## Profondeur d'imbrication

### Niveau 0 : Objet principal
L'objet demandé avec tous ses champs mappés.

### Niveau 1 : Objets liés
Les FK vers objets métier sont résolues avec leurs champs de base.

### Niveau 1 : Dictionnaires
Les FK vers dictionnaires sont résolues avec code + label.

### Pas de niveau 2+
Les objets embarqués ne contiennent **pas** leurs propres FK résolues en objets.

#### Exemple

```json
// GET /interventions/123
{
  "id": 123,
  "ref": "FI2024-0042",
  "thirdparty": {
    "id": 456,
    "name": "ACME Corp",
    "city": "Paris",
    "country": {
      "code": "FR",
      "label": "France"
    }
  }
}
```

> **Exception** : Les dictionnaires (code + label) sont toujours résolus, même dans les objets de niveau 1, car ce sont des données légères et essentielles à l'affichage.

---

## Champs calculés / enrichis

Certains champs peuvent être ajoutés par le mapping pour faciliter l'utilisation côté client.

| Champ | Description |
|-------|-------------|
| `status_label` | Libellé traduit du statut |
| `total_ttc` | Total TTC formaté |
| `full_address` | Adresse complète concaténée |
| `full_name` | Prénom + Nom |

---

## Types spéciaux SmartAuth

Pour les extrafields avec préfixes spéciaux :

| Préfixe Dolibarr | Type Front | Description |
|------------------|------------|-------------|
| `smartphoto_*` | `photos` | Galerie photos |
| `smartaudio_*` | `audios` | Fichiers audio |
| `smartvideo_*` | `videos` | Fichiers vidéo |
| `smartfile_*` | `files` | Documents |
| `smartsignature_*` | `signature` | Signature |

---

## Nommage des classes de mapping

Les noms des classes de mapping suivent la convention de l'API officielle Dolibarr (en anglais).

| Classe Dolibarr | Classe de mapping | Alias (rétrocompatibilité) |
|-----------------|-------------------|----------------------------|
| `Facture` | `dmInvoice` | `dmFacture` |
| `Propal` | `dmProposal` | `dmPropal` |
| `Commande` | `dmOrder` | `dmCommande` |
| `Contrat` | `dmContract` | `dmContrat` |
| `Fichinter` | `dmIntervention` | `dmFichinter` |
| `Societe` | `dmThirdparty` | `dmSociete` |
| `ActionComm` | `dmAgendaEvent` | `dmActionComm` |
| `Contact` | `dmContact` | - |
| `Project` | `dmProject` | - |
| `Product` | `dmProduct` | - |
| `User` | `dmUser` | - |
| `Task` | `dmTask` | - |
| `FactureFournisseur` | `dmSupplierInvoice` | `dmFactureFournisseur` |
| `CommandeFournisseur` | `dmSupplierOrder` | `dmCommandeFournisseur` |
| `SupplierProposal` | `dmSupplierProposal` | - |
| `Expedition` | `dmShipment` | `dmExpedition` |
| `Reception` | `dmReception` | - |
| `Entrepot` | `dmWarehouse` | `dmEntrepot` |
| `ExpenseReport` | `dmExpenseReport` | - |
| `BOM` | `dmBom` | - |
| `Mo` | `dmMo` | - |
| `Ticket` | `dmTicket` | - |
| `Adherent` | `dmMember` | `dmAdherent` |
| `AdherentType` | `dmMemberType` | `dmAdherentType` |
| `Don` | `dmDonation` | `dmDon` |
| `Categorie` | `dmCategory` | `dmCategorie` |
| `Subscription` | `dmSubscription` | - |
| `MultiCurrency` | `dmMulticurrency` | - |

> **Note** : Les alias sont définis via `class_alias()` pour assurer la rétrocompatibilité avec le code existant qui utilise les noms français.

---

## Lignes de documents (Invoice, Proposal, Order)

Les documents commerciaux (factures, devis, commandes) contiennent des lignes. Ces lignes suivent un mapping commun défini dans `dmLinesTrait.php`.

### Structure des lignes

```json
{
  "id": 123,
  "ref": "FA2024-0001",
  "thirdparty": { ... },
  "lines": [
    {
      "id": 1,
      "position": 1,
      "product_id": 42,
      "product": {
        "id": 42,
        "ref": "PROD001",
        "label": "Mon Produit"
      },
      "description": "Description de la ligne",
      "quantity": 2,
      "unit_price_excl_tax": 100.00,
      "discount_percent": 10,
      "vat_rate": 20,
      "total_excl_tax": 180.00,
      "total_vat": 36.00,
      "total_incl_tax": 216.00
    }
  ]
}
```

### Champs communs des lignes

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `rowid` | `id` | Identifiant de la ligne |
| `rang` | `position` | Position dans le document |
| `fk_parent_line` | `parent_line_id` | ID ligne parente (sous-lignes) |
| `fk_product` | `product` | Produit (ID + objet embarqué) |
| `product_type` | `product_type` | Type (0=produit, 1=service) |
| `product_ref` | `product_ref` | Référence produit |
| `product_label` | `product_label` | Libellé produit |
| `desc` | `description` | Description de la ligne |
| `qty` | `quantity` | Quantité |
| `subprice` | `unit_price_excl_tax` | Prix unitaire HT |
| `remise_percent` | `discount_percent` | Remise en % |
| `tva_tx` | `vat_rate` | Taux de TVA |
| `total_ht` | `total_excl_tax` | Total HT |
| `total_tva` | `total_vat` | Total TVA |
| `total_ttc` | `total_incl_tax` | Total TTC |
| `date_start` | `date_start` | Date début (services) |
| `date_end` | `date_end` | Date fin (services) |

### Champs multicurrency des lignes

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `multicurrency_code` | `multicurrency_code` | Code devise |
| `multicurrency_subprice` | `multicurrency_unit_price` | Prix unitaire devise |
| `multicurrency_total_ht` | `multicurrency_total_excl_tax` | Total HT devise |
| `multicurrency_total_tva` | `multicurrency_total_vat` | Total TVA devise |
| `multicurrency_total_ttc` | `multicurrency_total_incl_tax` | Total TTC devise |

### Champs marge des lignes

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `pa_ht` | `buy_price_excl_tax` | Prix d'achat HT |
| `marge_tx` | `margin_rate` | Taux de marge |
| `marque_tx` | `markup_rate` | Taux de marque |

### Champs spécifiques par type

#### Facture (FactureLigne)

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `situation_percent` | `situation_percent` | % situation (factures de situation) |
| `fk_prev_id` | `previous_situation_line_id` | Ligne situation précédente |
| `fk_code_ventilation` | `accounting_code_id` | Code comptable |

#### Commande (OrderLine)

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `ref_ext` | `external_ref` | Référence externe |
| `product_tobatch` | `product_batch_enabled` | Produit géré en lot |

#### Contrat (ContratLigne)

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `date_ouverture_prevue` | `date_start_planned` | Date début prévue |
| `date_ouverture` | `date_start_real` | Date début réelle |
| `date_fin_validite` | `date_end_planned` | Date fin prévue |
| `date_cloture` | `date_end_real` | Date fin réelle |
| `statut` | `status` | Statut de la ligne |

#### Intervention (FichinterLigne)

| Dolibarr | Front | Description |
|----------|-------|-------------|
| `date` | `date` | Date de l'intervention |
| `duree` | `duration` | Durée (en secondes) |

---

## Dictionnaires

Les dictionnaires Dolibarr sont exposés avec leurs champs standards.

| Table Dolibarr | Classe de mapping | Description |
|----------------|-------------------|-------------|
| `c_country` | `dmCcountry` | Pays |
| `c_departements` | `dmCstate` | Départements/États |
| `c_civility` | `dmCcivility` | Civilités |
| `c_payment_term` | `dmCpaymentterm` | Conditions de règlement |
| `c_paiement` | `dmCpaymenttype` | Modes de règlement |
| `c_typent` | `dmCtypent` | Types d'entreprise |
| `c_stcomm` | `dmCstcomm` | Statuts commerciaux |
| `c_units` | `dmCunits` | Unités de mesure |
| `c_actioncomm` | `dmCactiontype` | Types d'événements agenda |
| `c_prospectlevel` | `dmCprospectstatus` | Niveaux de prospect |
| `c_incoterms` | `dmCincoterm` | Incoterms |
| `c_shipment_mode` | `dmCshipmentmode` | Modes d'expédition |
| `c_availability` | `dmCavailability` | Disponibilités (délais) |
| `c_type_contact` | `dmCtypecontact` | Types de contact sur documents |
| `c_ticket_type` | `dmCtickettype` | Types de tickets |
| `c_ticket_severity` | `dmCticketseverity` | Niveaux de sévérité des tickets |
| `c_ticket_category` | `dmCticketcategory` | Catégories de tickets |
| `c_ticket_resolution` | `dmCticketresolution` | Résolutions de tickets |

---

## Objets liés (Linked Objects)

Les documents Dolibarr peuvent être liés entre eux (devis -> commande -> facture, etc.).
Le trait `dmLinkedObjectsTrait` permet d'exposer ces liens dans l'API.

### Structure

```json
{
  "id": 123,
  "ref": "CO2024-0001",
  "linked_objects": {
    "proposal": [
      {"id": 45, "type": "proposal", "ref": "PR2024-0012", "status": 2}
    ],
    "invoice": [
      {"id": 78, "type": "invoice", "ref": "FA2024-0034", "status": 1, "total_incl_tax": 1200.00}
    ]
  }
}
```

### Types d'objets liés

| Type Dolibarr | Type API |
|---------------|----------|
| `propal` | `proposal` |
| `commande` | `order` |
| `facture` | `invoice` |
| `contrat` | `contract` |
| `fichinter` | `intervention` |
| `supplier_proposal` | `supplier_proposal` |
| `order_supplier` | `supplier_order` |
| `invoice_supplier` | `supplier_invoice` |
| `shipping` | `shipment` |
| `reception` | `reception` |

---

## Récapitulatif des transformations Dolibarr -> Front

```
rowid           ->  id
nom             ->  name
town            ->  city
fk_pays         ->  country { code, label }
fk_departement  ->  state { code, label }
fk_soc          ->  thirdparty_id + thirdparty { ... }
fk_projet       ->  project_id + project { ... }
datec           ->  created_at
tms             ->  updated_at
dateo           ->  date_start
datee           ->  date_end
datei           ->  date_intervention
ref_client      ->  customer_ref
phone_mobile    ->  mobile
note_public     ->  public_note
note_private    ->  private_note
```
