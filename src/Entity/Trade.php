<?php

namespace App\Entity;

use App\Repository\TradeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeRepository::class)]
class Trade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $asset = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $entryDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $exitDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $watchlistDate = null;

    #[ORM\ManyToMany(targetEntity: Timeframe::class, inversedBy: 'trades')]
    private Collection $timeframes;

    #[ORM\Column(length: 20)]
    private ?string $orderType = null;

    #[ORM\Column(type: Types::FLOAT)]
    private ?float $riskPercentage = null;

    #[ORM\ManyToOne(targetEntity: Result::class, inversedBy: 'trades')]
    private ?Result $result = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $initialRR = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $finalRR = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gainRR = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gainEuro = null;

    #[ORM\Column(type: Types::FLOAT)]
    private ?float $maxRiskEuro = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $day = null;

    #[ORM\ManyToOne(targetEntity: TradeType::class, inversedBy: 'trades')]
    private ?TradeType $tradeType = null;

    #[ORM\ManyToOne(targetEntity: Trend::class, inversedBy: 'trades')]
    private ?Trend $trend = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private ?bool $tradeManagement = false;

    #[ORM\ManyToOne(targetEntity: TradeError::class, inversedBy: 'trades')]
    private ?TradeError $error = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $goodTrade = null;

    #[ORM\Column(length: 20)]
    private ?string $status = 'watching';

    #[ORM\ManyToMany(targetEntity: Confluence::class, inversedBy: 'trades')]
    private Collection $confluences;

    #[ORM\ManyToMany(targetEntity: Setup::class, inversedBy: 'trades')]
    private Collection $setups;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $executionReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $noteErrors = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $executionScreenshots = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $managementScreenshots = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $closingScreenshots = null;

    public function __construct()
    {
        $this->timeframes = new ArrayCollection();
        $this->confluences = new ArrayCollection();
        $this->setups = new ArrayCollection();
        $this->watchlistDate = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): ?string
    {
        return $this->asset;
    }

    public function setAsset(string $asset): self
    {
        $this->asset = $asset;
        return $this;
    }

    public function getEntryDate(): ?\DateTimeInterface
    {
        return $this->entryDate;
    }

    public function setEntryDate(?\DateTimeInterface $entryDate): self
    {
        $this->entryDate = $entryDate;
        $this->calculateStatus();
        $this->calculateDay();
        return $this;
    }

    public function getExitDate(): ?\DateTimeInterface
    {
        return $this->exitDate;
    }

    public function setExitDate(?\DateTimeInterface $exitDate): self
    {
        $this->exitDate = $exitDate;
        $this->calculateStatus();
        return $this;
    }

    public function getWatchlistDate(): ?\DateTimeInterface
    {
        return $this->watchlistDate;
    }

    public function setWatchlistDate(\DateTimeInterface $watchlistDate): self
    {
        $this->watchlistDate = $watchlistDate;
        return $this;
    }

    /**
     * @return Collection<int, Timeframe>
     */
    public function getTimeframes(): Collection
    {
        return $this->timeframes;
    }

    public function addTimeframe(Timeframe $timeframe): self
    {
        if (!$this->timeframes->contains($timeframe)) {
            $this->timeframes->add($timeframe);
        }
        return $this;
    }

    public function removeTimeframe(Timeframe $timeframe): self
    {
        $this->timeframes->removeElement($timeframe);
        return $this;
    }

    public function getOrderType(): ?string
    {
        return $this->orderType;
    }

    public function setOrderType(string $orderType): self
    {
        $this->orderType = $orderType;
        return $this;
    }

    public function getRiskPercentage(): ?float
    {
        return $this->riskPercentage;
    }

    public function setRiskPercentage(float $riskPercentage): self
    {
        $this->riskPercentage = $riskPercentage;
        $this->calculateGainRR();
        $this->calculateGainEuro();
        return $this;
    }

    public function getResult(): ?Result
    {
        return $this->result;
    }

    public function setResult(?Result $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function getInitialRR(): ?float
    {
        return $this->initialRR;
    }

    public function setInitialRR(?float $initialRR): self
    {
        $this->initialRR = $initialRR;
        return $this;
    }

    public function getFinalRR(): ?float
    {
        return $this->finalRR;
    }

    public function setFinalRR(?float $finalRR): self
    {
        $this->finalRR = $finalRR;
        $this->calculateGainRR();
        $this->calculateGainEuro();
        return $this;
    }

    public function getGainRR(): ?float
    {
        return $this->gainRR;
    }

    public function setGainRR(?float $gainRR): self
    {
        $this->gainRR = $gainRR;
        return $this;
    }

    public function getGainEuro(): ?float
    {
        return $this->gainEuro;
    }

    public function setGainEuro(?float $gainEuro): self
    {
        $this->gainEuro = $gainEuro;
        return $this;
    }

    public function getMaxRiskEuro(): ?float
    {
        return $this->maxRiskEuro;
    }

    public function setMaxRiskEuro(float $maxRiskEuro): self
    {
        $this->maxRiskEuro = $maxRiskEuro;
        $this->calculateGainEuro();
        return $this;
    }

    public function getDay(): ?string
    {
        return $this->day;
    }

    public function setDay(?string $day): self
    {
        $this->day = $day;
        return $this;
    }

    public function getTradeType(): ?TradeType
    {
        return $this->tradeType;
    }

    public function setTradeType(?TradeType $tradeType): self
    {
        $this->tradeType = $tradeType;
        return $this;
    }

    public function getTrend(): ?Trend
    {
        return $this->trend;
    }

    public function setTrend(?Trend $trend): self
    {
        $this->trend = $trend;
        return $this;
    }

    public function isTradeManagement(): ?bool
    {
        return $this->tradeManagement;
    }

    public function setTradeManagement(bool $tradeManagement): self
    {
        $this->tradeManagement = $tradeManagement;
        return $this;
    }

    public function getError(): ?TradeError
    {
        return $this->error;
    }

    public function setError(?TradeError $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function isGoodTrade(): ?bool
    {
        return $this->goodTrade;
    }

    public function setGoodTrade(?bool $goodTrade): self
    {
        $this->goodTrade = $goodTrade;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return Collection<int, Confluence>
     */
    public function getConfluences(): Collection
    {
        return $this->confluences;
    }

    public function addConfluence(Confluence $confluence): self
    {
        if (!$this->confluences->contains($confluence)) {
            $this->confluences->add($confluence);
        }
        return $this;
    }

    public function removeConfluence(Confluence $confluence): self
    {
        $this->confluences->removeElement($confluence);
        return $this;
    }

    /**
     * @return Collection<int, Setup>
     */
    public function getSetups(): Collection
    {
        return $this->setups;
    }

    public function addSetup(Setup $setup): self
    {
        if (!$this->setups->contains($setup)) {
            $this->setups->add($setup);
        }
        return $this;
    }

    public function removeSetup(Setup $setup): self
    {
        $this->setups->removeElement($setup);
        return $this;
    }

    public function calculateStatus(): void
    {
        if ($this->entryDate === null) {
            $this->status = 'watching';
        } elseif ($this->exitDate === null) {
            $this->status = 'open';
        } else {
            $this->status = 'closed';
        }
    }

    public function calculateDay(): void
    {
        if ($this->entryDate) {
            $this->day = $this->entryDate->format('l');
        }
    }

    public function calculateGainRR(): void
    {
        if ($this->finalRR !== null && $this->riskPercentage !== null) {
            $this->gainRR = $this->finalRR * ($this->riskPercentage / 100);
        }
    }

    public function calculateGainEuro(): void
    {
        if ($this->gainRR !== null && $this->maxRiskEuro !== null) {
            $this->gainEuro = $this->gainRR * $this->maxRiskEuro;
        }
    }

    public function getExecutionReason(): ?string
    {
        return $this->executionReason;
    }

    public function setExecutionReason(?string $executionReason): self
    {
        $this->executionReason = $executionReason;
        return $this;
    }

    public function getNoteErrors(): ?string
    {
        return $this->noteErrors;
    }

    public function setNoteErrors(?string $noteErrors): self
    {
        $this->noteErrors = $noteErrors;
        return $this;
    }

    public function getExecutionScreenshots(): ?array
    {
        return $this->executionScreenshots;
    }

    public function setExecutionScreenshots(?array $executionScreenshots): self
    {
        $this->executionScreenshots = $executionScreenshots;
        return $this;
    }

    public function getManagementScreenshots(): ?array
    {
        return $this->managementScreenshots;
    }

    public function setManagementScreenshots(?array $managementScreenshots): self
    {
        $this->managementScreenshots = $managementScreenshots;
        return $this;
    }

    public function getClosingScreenshots(): ?array
    {
        return $this->closingScreenshots;
    }

    public function setClosingScreenshots(?array $closingScreenshots): self
    {
        $this->closingScreenshots = $closingScreenshots;
        return $this;
    }
}
