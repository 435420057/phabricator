<?php

/**
 * @group legalpad
 */
final class LegalpadDocumentQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $creatorPHIDs;
  private $contributorPHIDs;
  private $signerPHIDs;
  private $dateCreatedAfter;
  private $dateCreatedBefore;

  private $needDocumentBodies;
  private $needContributors;
  private $needSignatures;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withCreatorPHIDs(array $phids) {
    $this->creatorPHIDs = $phids;
    return $this;
  }

  public function withContributorPHIDs(array $phids) {
    $this->contributorPHIDs = $phids;
    return $this;
  }

  public function withSignerPHIDs(array $phids) {
    $this->signerPHIDs = $phids;
    return $this;
  }

  public function needDocumentBodies($need_bodies) {
    $this->needDocumentBodies = $need_bodies;
    return $this;
  }

  public function needContributors($need_contributors) {
    $this->needContributors = $need_contributors;
    return $this;
  }

  public function needSignatures($need_signatures) {
    $this->needSignatures = $need_signatures;
    return $this;
  }

  public function withDateCreatedBefore($date_created_before) {
    $this->dateCreatedBefore = $date_created_before;
    return $this;
  }

  public function withDateCreatedAfter($date_created_after) {
    $this->dateCreatedAfter = $date_created_after;
    return $this;
  }

  protected function loadPage() {
    $table = new LegalpadDocument();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT d.* FROM %T d %Q %Q %Q %Q',
      $table->getTableName(),
      $this->buildJoinClause($conn_r),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    $documents = $table->loadAllFromArray($data);

    return $documents;
  }

  protected function willFilterPage(array $documents) {
    if ($this->signerPHIDs) {
      $document_map = mpull($documents, null, 'getPHID');
      $signatures = id(new LegalpadDocumentSignature())
        ->loadAllWhere(
          'documentPHID IN (%Ls) AND signerPHID IN (%Ls)',
          array_keys($document_map),
          $this->signerPHIDs);
      $signatures = mgroup($signatures, 'getDocumentPHID');
      foreach ($document_map as $document_phid => $document) {
        $sigs = idx($signatures, $document_phid, array());
        foreach ($sigs as $index => $sig) {
          if ($sig->getDocumentVersion() != $document->getVersions()) {
            unset($sigs[$index]);
          }
        }
        $signer_phids = mpull($sigs, 'getSignerPHID');
        if (array_diff($this->signerPHIDs, $signer_phids)) {
          unset($documents[$document->getID()]);
        }
      }
    }

    if ($this->needDocumentBodies) {
      $documents = $this->loadDocumentBodies($documents);
    }

    if ($this->needContributors) {
      $documents = $this->loadContributors($documents);
    }

    if ($this->needSignatures) {
      $documents = $this->loadSignatures($documents);
    }

    return $documents;
  }

  private function buildJoinClause($conn_r) {
    $joins = array();

    if ($this->contributorPHIDs) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN edge e ON e.src = d.phid');
    }

    return implode(' ', $joins);
  }

  protected function buildWhereClause($conn_r) {
    $where = array();

    $where[] = $this->buildPagingClause($conn_r);

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'd.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'd.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->creatorPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'd.creatorPHID IN (%Ls)',
        $this->creatorPHIDs);
    }

    if ($this->dateCreatedAfter) {
      $where[] = qsprintf(
        $conn_r,
        'd.dateCreated >= %d',
        $this->dateCreatedAfter);
    }

    if ($this->dateCreatedBefore) {
      $where[] = qsprintf(
        $conn_r,
        'd.dateCreated <= %d',
        $this->dateCreatedBefore);
    }

    if ($this->contributorPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'e.type = %s AND e.dst IN (%Ls)',
        PhabricatorEdgeConfig::TYPE_OBJECT_HAS_CONTRIBUTOR,
        $this->contributorPHIDs);
    }

    return $this->formatWhereClause($where);
  }

  private function loadDocumentBodies(array $documents) {
    $body_phids = mpull($documents, 'getDocumentBodyPHID');
    $bodies = id(new LegalpadDocumentBody())->loadAllWhere(
      'phid IN (%Ls)',
      $body_phids);
    $bodies = mpull($bodies, null, 'getPHID');

    foreach ($documents as $document) {
      $body = idx($bodies, $document->getDocumentBodyPHID());
      $document->attachDocumentBody($body);
    }

    return $documents;
  }

  private function loadContributors(array $documents) {
    $document_map = mpull($documents, null, 'getPHID');
    $edge_type = PhabricatorEdgeConfig::TYPE_OBJECT_HAS_CONTRIBUTOR;
    $contributor_data = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array_keys($document_map))
      ->withEdgeTypes(array($edge_type))
      ->execute();

    foreach ($document_map as $document_phid => $document) {
      $data = $contributor_data[$document_phid];
      $contributors = array_keys(idx($data, $edge_type, array()));
      $document->attachContributors($contributors);
    }

    return $documents;
  }

  private function loadSignatures(array $documents) {
    $document_map = mpull($documents, null, 'getPHID');

    $signatures = id(new LegalpadDocumentSignature())
      ->loadAllWhere(
      'documentPHID IN (%Ls)',
      array_keys($document_map));
    $signatures = mgroup($signatures, 'getDocumentPHID');

    foreach ($documents as $document) {
      $sigs = idx($signatures, $document->getPHID());
      foreach ($sigs as $index => $sig) {
        if ($sig->getDocumentVersion() != $document->getVersions()) {
          unset($sigs[$index]);
        }
      }
      $document->attachSignatures($sigs);
    }

    return $documents;
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorApplicationLegalpad';
  }

}
